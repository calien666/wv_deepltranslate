<?php
declare(strict_types = 1);

namespace WebVision\WvDeepltranslate\Service;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2020 Ricky Mathew <ricky@web-vision.de>, web-vision GmbH
 *      Anu Bhuvanendran Nair <anu@web-vision.de>, web-vision GmbH
 *
 *  You may not remove or change the name of the author above. See:
 *  http://www.gnu.org/licenses/gpl-faq.html#IWantCredit
 *
 *  This script is part of the Typo3 project. The Typo3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use GuzzleHttp\Exception\ClientException;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use WebVision\WvDeepltranslate\Domain\Repository\GlossariesSyncRepository;
use WebVision\WvDeepltranslate\Domain\Repository\SettingsRepository;

class DeeplService
{
    public string $apiKey;

    public string $apiUrl;

    public string $deeplFormality;

    /**
     * Default supported languages
     *
     * @see https://www.deepl.com/de/docs-api/translating-text/#request
     * @var string[]
     */
    public array $apiSupportedLanguages =  [
        'source' => [],
        'target' => [],
    ];

    /**
     * Formality supported languages
     * @var string[]
     */
    public array $formalitySupportedLanguages = [];

    public RequestFactory $requestFactory;

    protected SettingsRepository $deeplSettingsRepository;

    protected GlossariesSyncRepository $glossariesSyncRepository;

    private FrontendInterface $cache;

    public function __construct(?FrontendInterface $cache = null)
    {
        $this->cache = $cache ?? GeneralUtility::makeInstance(CacheManager::class)->getCache('wvdeepltranslate');
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $this->deeplSettingsRepository = $objectManager->get(SettingsRepository::class);
        $this->glossariesSyncRepository = $objectManager->get(GlossariesSyncRepository::class);
        $this->requestFactory = GeneralUtility::makeInstance(RequestFactory::class);

        $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('wv_deepltranslate');
        $this->apiUrl = $extensionConfiguration['apiUrl'];
        $this->apiKey = $extensionConfiguration['apiKey'];
        $this->deeplFormality = $extensionConfiguration['deeplFormality'];

        $this->loadSupportedLanguages();
        $this->apiSupportedLanguages['target'] = $this->deeplSettingsRepository->getSupportedLanguages($this->apiSupportedLanguages['target']);
    }

    /**
     * Deepl Api Call for retrieving translation.
     * @return array
     */
    public function translateRequest($content, $targetLanguage, $sourceLanguage): array
    {
        $postFields = [
            'auth_key'     => $this->apiKey,
            'text'         => $content,
            'source_lang'  => urlencode($sourceLanguage),
            'target_lang'  => urlencode($targetLanguage),
            'tag_handling' => urlencode('xml'),
        ];

        // Implementation of glossary into translation
        $glossaryId = $this->glossariesSyncRepository->getGlossaryIdByLanguages($sourceLanguage, $targetLanguage);

        if (!empty($glossaryId)) {
            $postFields['glossary_id'] = $glossaryId;
        }

        if (!empty($this->deeplFormality) && in_array(strtoupper($targetLanguage), $this->formalitySupportedLanguages, true)) {
            $postFields['formality'] = $this->deeplFormality;
        }
        //url-ify the data to get content length
        $postFieldString = '';
        foreach ($postFields as $key => $value) {
            $postFieldString .= $key . '=' . $value . '&';
        }

        $postFieldString = rtrim($postFieldString, '&');
        $contentLength = mb_strlen($postFieldString, '8bit');

        try {
            $response = $this->requestFactory->request($this->apiUrl, 'POST', [
                'form_params' => $postFields,
                'headers'     => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Content-Length' => $contentLength,
                ],
            ]);
        } catch (ClientException $e) {
            $flashMessage = GeneralUtility::makeInstance(
                FlashMessage::class,
                $e->getMessage(),
                '',
                FlashMessage::INFO
            );
            GeneralUtility::makeInstance(FlashMessageService::class)
                ->getMessageQueueByIdentifier()
                ->addMessage($flashMessage);
            return [];
        }

        return json_decode($response->getBody()->getContents(), true);
    }

    private function loadSupportedLanguages(): void
    {
        $cacheIdentifier = 'wv-deepl-supported-languages-target';
        if (($supportedTargetLanguages = $this->cache->get($cacheIdentifier)) === false) {
            $supportedTargetLanguages = $this->loadSupportedLanguagesFromAPI();

            $this->cache->set($cacheIdentifier, $supportedTargetLanguages, [], 86400);
        }

        foreach ($supportedTargetLanguages as $supportedLanguage) {
            $this->apiSupportedLanguages['target'][] = $supportedLanguage['language'];
            if ($supportedLanguage['supports_formality'] === true) {
                $this->formalitySupportedLanguages[] = $supportedLanguage['language'];
            }
        }

        $cacheIdentifier = 'wv-deepl-supported-languages-source';

        if (($supportedSourceLanguages = $this->cache->get($cacheIdentifier)) === false) {
            $supportedSourceLanguages = $this->loadSupportedLanguagesFromAPI('source');

            $this->cache->set($cacheIdentifier, $supportedSourceLanguages, [], 86400);
        }

        foreach ($supportedSourceLanguages as $supportedLanguage) {
            $this->apiSupportedLanguages['source'][] = $supportedLanguage['language'];
            if ($supportedLanguage['supports_formality'] === true) {
                $this->formalitySupportedLanguages[] = $supportedLanguage['language'];
            }
        }
    }

    private function loadSupportedLanguagesFromAPI(string $type = 'target'): array
    {
        $mainApiUrl = parse_url($this->apiUrl);
        $languageApiUrl = sprintf(
            '%s://%s/v2/languages?type=%s',
            $mainApiUrl['scheme'],
            $mainApiUrl['host'],
            $type
        );

        $headers = [
            'Authorization' => sprintf('DeepL-Auth-Key %s', $this->apiKey),
        ];

        try {
            $response = $this->requestFactory->request($languageApiUrl, 'GET', [
                'headers' => $headers,
            ]);
        } catch (ClientException $e) {
            return [];
        }

        return json_decode($response->getBody()->getContents(), true);
    }
}

<?php
declare(strict_types=1);
namespace TYPO3\CMS\FrontendEditing\Service;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Class for fetching the proper RTE configuration for all fields of a record
 */
class EditorConfigurationService
{

    /**
     * Processed values from FormEngine
     * @var array
     */
    protected $formData;

    /**
     * @var array
     */
    protected $rteConfiguration;

    /**
     * @var array
     */
    protected $editorConfiguration;

    /**
     * Loads the CKEditor configuration for all available fields of a record
     * kicks FormEngine in since this is used to resolve the proper record type
     *
     * @return array
     */
    public function generateEditorConfiguration(): array
    {
        // @TODO: Fix so that other tables are taking into context
        $table = 'tt_content';

        /** @var TcaDatabaseRecord $formDataGroup */
        $formDataGroup = GeneralUtility::makeInstance(TcaDatabaseRecord::class);
        /** @var FormDataCompiler $formDataCompiler */
        $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class, $formDataGroup);

        $formDataCompilerInput = [
            'tableName' => $table,
            'command' => 'new',
            // done intentionally to speed up the compilation of the processedTca
            'disabledWizards' => true
        ];

        $formData = $formDataCompiler->compile($formDataCompilerInput);
        $fieldNames = array_keys($formData['processedTca']['columns']);

        foreach ($fieldNames as $fieldName) {
            $formDataFieldName = $formData['processedTca']['columns'][$fieldName];
            $rteConfiguration = $formDataFieldName['config']['richtextConfiguration']['editor'];
            if (is_array($rteConfiguration)) {
                $configuration = $this->prepareConfigurationForEditor($rteConfiguration);

                $externalPlugins = '';
                foreach ($this->getExtraPlugins($formData, $rteConfiguration) as $pluginName => $config) {
                    $configuration[$pluginName] = $config['config'];
                    $configuration['extraPlugins'] .= ',' . $pluginName;

                    $externalPlugins .= 'CKEDITOR.plugins.addExternal(';
                    $externalPlugins .= GeneralUtility::quoteJSvalue($pluginName) . ',';
                    $externalPlugins .= GeneralUtility::quoteJSvalue($config['resource']) . ',';
                    $externalPlugins .= '\'\');';
                }

                $data = [
                    'configuration' => $configuration,
                    'externalPlugins' => $externalPlugins,
                    'hasCkeditorConfiguration' => $rteConfiguration !== null
                ];

                $this->editorConfiguration[$fieldName] = $data;
            }
        }

        return $this->editorConfiguration;
    }

    /**
     * Get configuration of external/additional plugins
     *
     * @param array $formData
     * @param array $rteConfiguration
     * @return array
     */
    protected function getExtraPlugins(array $formData, array $rteConfiguration): array
    {
        $urlParameters = [
            'P' => [
                'table'      => $formData['tableName'],
                'uid'        => $formData['databaseRow']['uid'],
                'fieldName'  => $formData['fieldName'],
                'recordType' => $formData['recordTypeValue'],
                'pid'        => $formData['effectivePid'],
            ]
        ];

        $pluginConfiguration = [];
        if (isset($rteConfiguration['externalPlugins']) && is_array($rteConfiguration['externalPlugins'])) {
            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            foreach ($rteConfiguration['externalPlugins'] as $pluginName => $configuration) {
                $pluginConfiguration[$pluginName] = [
                    'resource' => $this->resolveUrlPath($configuration['resource'])
                ];
                unset($configuration['resource']);

                if ($configuration['route']) {
                    $configuration['routeUrl'] = (string)$uriBuilder->buildUriFromRoute(
                        $configuration['route'],
                        $urlParameters
                    );
                }

                $pluginConfiguration[$pluginName]['config'] = $configuration;
            }
        }
        return $pluginConfiguration;
    }

    /**
     * Add configuration to replace absolute EXT: paths with relative ones
     * @param array $configuration
     *
     * @return array
     */
    protected function replaceAbsolutePathsToRelativeResourcesPath(array $configuration): array
    {
        foreach ($configuration as $key => $value) {
            if (is_array($value)) {
                $configuration[$key] = $this->replaceAbsolutePathsToRelativeResourcesPath($value);
            } elseif (is_string($value) && substr($value, 0, 4) === 'EXT:') {
                $configuration[$key] = $this->resolveUrlPath($value);
            }
        }
        return $configuration;
    }

    /**
     * Resolves an EXT: syntax file to an absolute web URL
     *
     * @param string $value
     * @return string
     */
    protected function resolveUrlPath(string $value): string
    {
        $value = GeneralUtility::getFileAbsFileName($value);
        return PathUtility::getAbsoluteWebPath($value);
    }

    /**
     * Compiles the configuration set from the outside
     * to have it easily injected into the CKEditor.
     *
     * @param array $rteConfiguration
     * @return array the configuration
     */
    protected function prepareConfigurationForEditor(array $rteConfiguration): array
    {
        // Ensure custom config is empty so nothing additional is loaded
        // Of course this can be overriden by the editor configuration below
        $configuration = [
            'customConfig' => '',
        ];

        if (is_array($rteConfiguration['config'])) {
            $configuration = array_replace_recursive($configuration, $rteConfiguration['config']);
        }
        $configuration['contentsLanguage'] = $this->getLanguageIsoCodeOfContent();

        // replace all paths
        $configuration = $this->replaceAbsolutePathsToRelativeResourcesPath($configuration);

        // there are some places where we define an array, but it needs to be a list in order to work
        if (is_array($configuration['extraPlugins'])) {
            $configuration['extraPlugins'] = implode(',', $configuration['extraPlugins']);
        }
        if (is_array($configuration['removePlugins'])) {
            $configuration['removePlugins'] = implode(',', $configuration['removePlugins']);
        }
        if (is_array($configuration['removeButtons'])) {
            $configuration['removeButtons'] = implode(',', $configuration['removeButtons']);
        }

        return $configuration;
    }

    /**
     * Determine the contents language iso code
     *
     * @return string
     */
    protected function getLanguageIsoCodeOfContent(): string
    {
        $currentLanguageUid = $this->formData['databaseRow']['sys_language_uid'];
        if (is_array($currentLanguageUid)) {
            $currentLanguageUid = $currentLanguageUid[0];
        }
        $contentLanguageUid = (int)max($currentLanguageUid, 0);
        if ($contentLanguageUid) {
            $contentLanguage = $this->formData['systemLanguageRows'][$currentLanguageUid]['iso'];
        } else {
            $contentLanguage = $this->rteConfiguration['config']['defaultContentLanguage'] ?? 'en_US';
            $languageCodeParts = explode('_', $contentLanguage);
            $contentLanguage = strtolower($languageCodeParts[0]) . ($languageCodeParts[1]
                    ? '_' . strtoupper($languageCodeParts[1]) : '');
            // Find the configured language in the list of localization locales
            $locales = GeneralUtility::makeInstance(Locales::class);
            // If not found, default to 'en'
            if (!in_array($contentLanguage, $locales->getLocales(), true)) {
                $contentLanguage = 'en';
            }
        }
        return $contentLanguage;
    }
}

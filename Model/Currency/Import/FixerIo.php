<?php

namespace Jlazar\Fixerio\Model\Currency\Import;

use \Magento\Store\Model\ScopeInterface;

class FixerIo extends \Magento\Directory\Model\Currency\Import\FixerIo
{
    private $scopeConfig;

    const CURRENCY_CONVERTER_URL = 'https://api.apilayer.com/fixer/latest?symbols{{CURRENCY_TO}}&base={{CURRENCY_FROM}}'; // changed constant
    
    const API_KEY_CONFIG_PATH = 'currency/fixerio/api_key';
    
    /**
     * Initialize dependencies
     *
     * @param \Magento\Directory\Model\CurrencyFactory $currencyFactory
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory
     */
    public function __construct(
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory
    ) {
        parent::__construct($currencyFactory, $scopeConfig, $httpClientFactory);
        $this->scopeConfig = $scopeConfig;
    }

    public function fetchRates()
    {
        $data = [];
        $currencies = $this->_getCurrencyCodes();
        $defaultCurrencies = $this->_getDefaultCurrencyCodes();

        foreach ($defaultCurrencies as $currencyFrom) {
            if (!isset($data[$currencyFrom])) {
                $data[$currencyFrom] = [];
            }
            $data = $this->convertBatch($data, $currencyFrom, $currencies);
            ksort($data[$currencyFrom]);
        }
        return $data;
    }

    /**
     * Return currencies convert rates in batch mode
     *
     * @param array $data
     * @param string $currencyFrom
     * @param array $currenciesTo
     * @return array
     */
    private function convertBatch(array $data, string $currencyFrom, array $currenciesTo): array
    {
        $accessKey = $this->scopeConfig->getValue(self::API_KEY_CONFIG_PATH, ScopeInterface::SCOPE_STORE);
        
        if (empty($accessKey)) {
            $this->_messages[] = __('No API Key was specified or an invalid API Key was specified.');
            $data[$currencyFrom] = $this->makeEmptyResponse($currenciesTo);
            return $data;
        }

        $currenciesStr = implode(',', $currenciesTo);
        $url = str_replace(
            ['{{ACCESS_KEY}}', '{{CURRENCY_FROM}}', '{{CURRENCY_TO}}'],
            [$accessKey, $currencyFrom, $currenciesStr],
            self::CURRENCY_CONVERTER_URL
        );
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        set_time_limit(0);
        try {
            $response = $this->getServiceResponse($url);
        } finally {
            ini_restore('max_execution_time');
        }

        if (!$this->validateResponse($response, $currencyFrom)) {
            $data[$currencyFrom] = $this->makeEmptyResponse($currenciesTo);
            return $data;
        }

        foreach ($currenciesTo as $currencyTo) {
            if ($currencyFrom == $currencyTo) {
                $data[$currencyFrom][$currencyTo] = $this->_numberFormat(1);
            } else {
                if (empty($response['rates'][$currencyTo])) {
                    $serviceHost =  $this->getServiceHost($url);
                    $this->_messages[] = __('We can\'t retrieve a rate from %1 for %2.', $serviceHost, $currencyTo);
                    $data[$currencyFrom][$currencyTo] = null;
                } else {
                    $data[$currencyFrom][$currencyTo] = $this->_numberFormat(
                        (double)$response['rates'][$currencyTo]
                    );
                }
            }
        }
        return $data;
    }

    /**
     * @param string $url
     * @param int $retry
     * @return array|mixed
     * @override
     * @see \Magento\Directory\Model\Currency\Import\FixerIo::convertBatch()
     */
    private function getServiceResponse($url, $retry = 0)
    {
        $accessKey = $this->scopeConfig->getValue(self::API_KEY_CONFIG_PATH, ScopeInterface::SCOPE_STORE);
        
        
        /** @var \Magento\Framework\HTTP\ZendClient $httpClient */
        $httpClient = $this->httpClientFactory->create();
        $response = [];
        
        try {
            
            $jsonResponse = $httpClient->setUri($url)
                ->setConfig(
                    [
                        'timeout' => $this->scopeConfig->getValue(
                            'currency/fixerio/timeout',
                            ScopeInterface::SCOPE_STORE
                        ),
                    ]
                )
                ->setHeaders([
                    'Content-Type' => 'text/plain',
                    'apikey' => $accessKey,
                ])
                ->request('GET')
                ->getBody();

            $response = json_decode($jsonResponse, true);
            
        } catch (\Exception $e) {
            if ($retry == 0) {
                $response = $this->getServiceResponse($url, 1);
            }
        }
        return $response;
    }

    /**
     * Validates rates response.
     *
     * @param array $response
     * @param string $baseCurrency
     * @return bool
     */
    private function validateResponse(array $response, string $baseCurrency): bool
    {
        if ($response['success']) {
            return true;
        }

        $errorCodes = [
            101 => __('No API Key was specified or an invalid API Key was specified.'),
            102 => __('The account this API request is coming from is inactive.'),
            105 => __('The "%1" is not allowed as base currency for your subscription plan.', $baseCurrency),
            201 => __('An invalid base currency has been entered.'),
        ];

        $this->_messages[] = $errorCodes[$response['error']['code']] ?? __('Currency rates can\'t be retrieved.');

        return false;
    }

    /**
     * Creates array for provided currencies with empty rates.
     *
     * @param array $currenciesTo
     * @return array
     */
    private function makeEmptyResponse(array $currenciesTo): array
    {
        return array_fill_keys($currenciesTo, null);
    }

    /**
     * Get currency converter service host.
     *
     * @param string $url
     * @return string
     */
    private function getServiceHost(string $url): string
    {
        if (!$this->currencyConverterServiceHost) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $this->currencyConverterServiceHost = parse_url($url, PHP_URL_SCHEME) . '://'
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                . parse_url($url, PHP_URL_HOST);
        }
        return $this->currencyConverterServiceHost;
    }

}
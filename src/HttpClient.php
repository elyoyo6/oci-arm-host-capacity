<?php


namespace Hitrov;


use Hitrov\Exception\ApiCallException;
use Hitrov\Exception\CurlException;
use JsonException;

class HttpClient
{
    /**
     * @param array $curlOptions
     * @return array
     * @throws ApiCallException
     * @throws JsonException|CurlException
     */
    public static function getResponse(array $curlOptions): array
    {
        
        $responseHeaders = [];

        $curl = curl_init();

        $curlOptions[CURLOPT_HEADERFUNCTION] = function ($curl, $headerLine) use (&$responseHeaders) {
            $length = strlen($headerLine);
            $headerLine = trim($headerLine);

            if ($headerLine !== '' && strpos($headerLine, ':') !== false) {
                [$name, $value] = explode(':', $headerLine, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }

            return $length;
        };

        curl_setopt_array($curl, $curlOptions);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $errNo = curl_errno($curl);
        $info = curl_getinfo($curl);
        var_dump($info['http_code']);
        var_dump($info['redirect_count']);
        var_dump($info['redirect_url']);
        curl_close($curl);


        if ($response === false || ($error && $errNo)) {
            throw new CurlException("curl error occurred: $error, response: $response", $errNo);
        }

        $responseArray = json_decode($response, true);
        $jsonError = json_last_error();
        $logResponse = $response;
        if (!$jsonError) {
            $logResponse = json_encode($responseArray, JSON_PRETTY_PRINT);
        }

        
        if ($info['http_code'] < 200 || $info['http_code'] >= 300) {
            $requestId = $responseHeaders['opc-request-id'] ?? 'no disponible';

            throw new ApiCallException(
                "HTTP {$info['http_code']}\n" .
                "opc-request-id: {$requestId}\n" .
                "URL: {$info['url']}\n" .
                "Respuesta OCI:\n{$logResponse}",
                $info['http_code']
            );
        }


        if ($jsonError) {
            $jsonErrorMessage = json_last_error_msg();
            throw new JsonException("JSON error occurred: $jsonError ($jsonErrorMessage), response: \n$logResponse");
        }

        return $responseArray;
    }
}

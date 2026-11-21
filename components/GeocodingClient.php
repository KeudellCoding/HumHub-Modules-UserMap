<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\components;

use humhub\libs\HttpClient;

class GeocodingClient extends HttpClient
{
    public function beforeSend($request)
    {
        parent::beforeSend($request);
        // Keep HumHub's proxy options and enforce TLS for credentials and profile addresses.
        $request->addOptions([
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
    }

    public function createRequestLogToken($method, $url, $headers, $content)
    {
        return strtoupper($method) . ' ' . explode('?', $url, 2)[0];
    }
}

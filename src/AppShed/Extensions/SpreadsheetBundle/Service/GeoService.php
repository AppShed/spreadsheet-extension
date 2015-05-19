<?php

namespace AppShed\Extensions\SpreadsheetBundle\Service;

use Doctrine\Common\Cache\FilesystemCache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Subscriber\Cache\CacheStorage;
use GuzzleHttp\Subscriber\Cache\CacheSubscriber;
use Psr\Log\LoggerInterface;

/**
 * Description of GeoService
 *
 * @author Victor
 */
class GeoService {

    private $geoClient;

    private $logger;

    public function __construct(Client $geoClient, LoggerInterface $logger )
    {
        $this->geoClient = $geoClient;
        $this->logger = $logger;
    }

    public function getGeo($address)
    {
        try {
            CacheSubscriber::attach($this->geoClient);

            $geoResponse = $this->geoClient->get(
                '',
                [
                    'query' => [
                        'address' => $address
                    ]
                ]
            );

            if ($geoResponse->getStatusCode() != 200) {
                return false;
            }

            $resp = $geoResponse->json();

            if ($resp['status'] != 'OK' || !count($resp['results'])) {
                return false;
            }

            return $resp['results'][0]['geometry']['location'];

        } catch(RequestException $e ) {
            $this->logger->error(
                'Problem reading a spreadsheet',
                [
                    'exception' => $e
                ]
            );
        }

        return false;

    }

}

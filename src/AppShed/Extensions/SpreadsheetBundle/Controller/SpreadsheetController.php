<?php
namespace AppShed\Extensions\SpreadsheetBundle\Controller;


use AppShed\Extensions\SpreadsheetBundle\Service\GeoService;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Google\Spreadsheet\SpreadsheetFeed;
use Google\Spreadsheet\SpreadsheetService;
use Google_Auth_AssertionCredentials;
use Google_Client;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use ZendGData\Spreadsheets;
use Google\Spreadsheet\DefaultServiceRequest;
use Google\Spreadsheet\ServiceRequestFactory;

abstract class SpreadsheetController extends Controller
{
    /**
     * @var Spreadsheets
     */
    protected $spreadsheets;

    /**
     * @var Registry
     */
    protected $doctrine;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var GeoService
     */
    protected $geoService;


    public function __construct( Registry $doctrine, LoggerInterface $logger, GeoService $geoService, $google_client_id, $google_client_email )
    {
        $client = new Google_Client();

        $client->setApplicationName('Spreadsheet');
        $client->setClientId($google_client_id); //client id

        $cred = new Google_Auth_AssertionCredentials(
            $google_client_email, //email
            ['https://spreadsheets.google.com/feeds', 'https://docs.google.com/feeds'],
            file_get_contents(__DIR__.'/../Spreadsheet-c9b3bcd23b99.p12') //p12 file dir
        );

        $client->setAssertionCredentials($cred);

        if ($client->getAuth()->isAccessTokenExpired()) {
            $client->getAuth()->refreshTokenWithAssertion($cred);
        }
        $accessToken = json_decode($client->getAccessToken());
        $accessToken = $accessToken->access_token;


        $serviceRequest = new DefaultServiceRequest($accessToken);
        ServiceRequestFactory::setInstance($serviceRequest);

        $spreadsheetService = new SpreadsheetService();

        $this->spreadsheets = $spreadsheetService;
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->geoService = $geoService;
    }

    /**
     * @return \Doctrine\Bundle\DoctrineBundle\Registry
     */
    public function getDoctrine()
    {
        return $this->doctrine;
    }

    /**
     * @return \Psr\Log\LoggerInterface
     */
    protected function getLogger()
    {
        return $this->logger;
    }

    /**
     * @return SpreadsheetFeed
     */
    protected function getSpreadsheets()
    {
        return $this->spreadsheets;
    }

    /**
     * Finds the key query param from a url
     *
     * @param $docUrl
     * @return string
     */
    protected function getKey($docUrl) 
	{
        preg_match('/([a-zA-Z0-9_-]){44}/',$docUrl,$matches);
        if (isset($matches['0'])) {
            return $matches['0'];
        }
        return null;
    }
}

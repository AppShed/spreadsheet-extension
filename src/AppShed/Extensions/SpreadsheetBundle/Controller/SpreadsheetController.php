<?php
namespace AppShed\Extensions\SpreadsheetBundle\Controller;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use ZendGData\Spreadsheets;

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

    public function __construct(Spreadsheets $spreadsheets, Registry $doctrine, LoggerInterface $logger)
    {
        $this->spreadsheets = $spreadsheets;
        $this->doctrine = $doctrine;
        $this->logger = $logger;
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
     * @return \ZendGData\Spreadsheets
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
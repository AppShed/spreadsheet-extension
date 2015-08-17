<?php

namespace AppShed\Extensions\SpreadsheetBundle\Controller;

use AppShed\Extensions\SpreadsheetBundle\Exceptions\SpreadsheetNotFoundException;
use AppShed\Remote\Element\Item\HTML;
use AppShed\Remote\Element\Item\Text;
use AppShed\Remote\Element\Screen\Screen;
use AppShed\Remote\HTML\Remote;
use Google\Spreadsheet\Spreadsheet;
use Google\Spreadsheet\SpreadsheetFeed;
use Google\Spreadsheet\Worksheet;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use AppShed\Extensions\SpreadsheetBundle\Entity\Doc;
use ZendGData\Spreadsheets\DocumentQuery;

/**
 * @Route("/spreadsheet/write", service="app_shed_extensions_spreadsheet.controller.write")
 */
class WriteController extends SpreadsheetController
{

    /**
     * @Route("/edit")
     * @Route("/edit/")
     * @Template()
     */
    public function indexAction(Request $request)
    {
        $action = '';
        $secret = $request->get('identifier');

        $em = $this->getDoctrine()->getManager();
        $doc = $em->getRepository('AppShedExtensionsSpreadsheetBundle:Doc')->findOneBy(array('itemsecret' => $secret));


        if (is_null($doc)) {
            $doc = new Doc();
            $doc->setKey('');
            $doc->setUrl('');
            $doc->setTitles(array());
            $doc->setFilters(array());
            $doc->setItemsecret($secret);
            $doc->setDate(new \DateTime());
        }


        if ($request->isMethod('post')) {

            $url = $request->get('url');
            $key = $this->getKey($url);
            $action = $request->get('action', false);

            try {
                /** @var Spreadsheet $document */
                $document = $this->getDocument($key);
                $worksheets = $document->getWorksheets();
                $worksheet = $worksheets[0];

                if ($worksheet) {
                    $lines = $worksheet->getListFeed()->getEntries();
                    $titles = [];
                    if (is_array($lines) && isset($lines[0])) {
                        $lines = $lines[0]->getValues();
                        $titles = array_keys($lines);
                    }

                    $doc->setUrl($url);
                    $doc->setKey($key);
                    $doc->setTitles($titles);

                    $em->persist($doc);
                    $em->flush();
                }

            } catch (SpreadsheetNotFoundException $e) {
                $this->logger->error(
                    'Spreadsheet not found',
                    [
                        'exception' => $e
                    ]
                );

                return array(
                    'doc' => $doc,
                    'action' => $action,
                    'error' => 'Could not access the document'
                );
            }
        }

        return array(
            'doc' => $doc,
            'action' => $action
        );
    }

    /**
     * @Route("/document")
     * @Route("/document/")
     */
    public function documentAction(Request $request)
    {
        if (Remote::isOptionsRequest()) {
            return Remote::getCORSSymfonyResponse();
        }

        $rowData = $this->cleanData(Remote::getRequestVariables());

        $secret = $request->get('identifier');

        $em = $this->getDoctrine()->getManager();
        /** @var Doc $doc */
        $doc = $em->getRepository('AppShedExtensionsSpreadsheetBundle:Doc')->findOneBy(array('itemsecret' => $secret));

        if (!$doc) {
            $screen = new Screen('Error');
            $screen->addChild(new HTML('You must setup the extension before using it'));
            return (new Remote($screen))->getSymfonyResponse();
        }

        try {
            $existingTitles = $this->getColumnTitles($doc->getKey());

            $store = false;
            foreach ($rowData as $titleName => $value) {
                if (!in_array($titleName, $existingTitles)) {
                    $store = true;
                    $this->addTitle($titleName, $doc->getKey());
                    $existingTitles[] = $titleName;
                }
            }

            if ($store) {
                $doc->setTitles($existingTitles);
                $em->flush();
            }

            foreach ($existingTitles as $titleName) {
                if (!isset($rowData[$titleName])) {
                    $rowData[$titleName] = '';
                }
            }

            if (count($rowData) > 0) {
                /** @var Spreadsheet $document */
                $document = $this->getDocument($doc->getKey());
                $worksheets = $document->getWorksheets();
                $worksheet = $worksheets[0];
                $worksheet->getListFeed()->insert($rowData);
            }
        } catch (\Exception $e) {
            $screen = new Screen('Error');
            $screen->addChild(new HTML('There was an error storing'));
            $screen->addChild(new Text($e->getMessage()));

            $this->logger->error(
                'Problem accessing a spreadsheet',
                [
                    'exception' => $e,
                    'rowData' => $rowData
                ]
            );
            return (new Remote($screen))->getSymfonyResponse();
        }

        $screen = new Screen('Saved');
        $screen->addChild(new HTML("Your record has been saved"));
        return (new Remote($screen))->getSymfonyResponse();
    }

    private function getColumnTitles($key)
    {

        $titles = array();

        /** @var Spreadsheet $document */
        $document = $this->getDocument($key);
        $worksheets = $document->getWorksheets();
        $worksheet = $worksheets[0];

        if ($worksheet) {
            $lines = $worksheet->getListFeed()->getEntries();
            if (is_array($lines) && isset($lines[0])) {
                $lines = $lines[0]->getValues();

                $titles = array_keys($lines);
            }
        }
        return $titles;
    }

    /**
     * @param $key
     * @throws SpreadsheetNotFoundException
     * @return \ZendGData\Spreadsheets\WorksheetEntry
     */
    protected function getDocument($key)
    {
        $feed = $this->getSpreadsheets()->getSpreadsheetById($key);

        if (!$feed) {
            throw new SpreadsheetNotFoundException("Failed to find spreadsheet $key");
        }

        return $feed;
    }

    /**
     * Add a new title to the spreadsheet
     *
     * @param $name
     * @param $key
     */
    private function addTitle($name, $key)
    {
        /** @var Spreadsheet $document */
        $document = $this->getDocument($key);
        $worksheets = $document->getWorksheets();
        $worksheet = $worksheets[0];
        /** @var Worksheet $worksheet */
        if ($worksheet) {
            $cellEntry = $worksheet->getCellFeed()->createInsertionCell(1, $this->findEmptyColumn($key), $name)->update($name);
        }
    }

    /**
     * Find the next empty cell in the first row
     *
     * @param $key
     * @return int
     */
    private function findEmptyColumn($key)
    {
        return count($this->getColumnTitles($key))+1;
    }

    /**
     * The api doesn't like keys with spaces, _ etc
     *
     * @param array $data
     * @return array
     */
    private function cleanData($data)
    {
        $rowData = [];
        foreach ($data as $titleName => $value) {
            if (!is_array($value) && trim($value) != '') {
                $rowData[preg_replace('/[^a-z]/', '', strtolower($titleName))] = $value;
            }
        }
        return $rowData;
    }
}

<?php

namespace AppShed\Extensions\SpreadsheetBundle\Controller;

use AppShed\Remote\Element\Item\HTML;
use AppShed\Remote\Element\Item\Link;
use AppShed\Remote\Element\Item\Marker;
use AppShed\Remote\Element\Item\Text;
use AppShed\Remote\Element\Screen\Map;
use AppShed\Remote\Element\Screen\Screen;
use AppShed\Remote\HTML\Remote;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use AppShed\Extensions\SpreadsheetBundle\Entity\Doc;

/**
 * @Route("/spreadsheet/read", service="app_shed_extensions_spreadsheet.controller.read")
 */
class ReadController extends SpreadsheetController
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
        $doc = $em->getRepository('AppShedExtensionsSpreadsheetBundle:Doc')->findOneBy(['itemsecret' => $secret]);

        $errors = '';

        if (is_null($doc)) {
            $doc = new Doc();
            $doc->setKey('');
            $doc->setUrl('');
            $doc->setTitles([]);
            $doc->setFilters([]);
            $doc->setItemsecret($secret);
            $doc->setDate(new \DateTime());
        }


        if ($request->isMethod('post')) {

            $url = $request->get('url', false);

            $address = $request->get('address');

            $action = $request->get('action', false);

            $key = $this->getKey($url);
            if ($url && $key) {

                $filters = $request->get('filters', []);

                $doc->setAddress($address);
                $doc->setUrl($url);
                $doc->setKey($key);
                $doc->setTitles($this->getRowTitles($key));
                $doc->setFilters(array_values($filters));

                $em->persist($doc);
                $em->flush();
            } else {
                if (!$url) {
                    $errors = 'Spreadsheet url is empty';
                } else {
                    $errors = 'Spreadsheet url is not supported or broken';
                }
            }
        }

        return [
            'doc' => $doc,
            'action' => $action,
            'error' => $errors
        ];
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

        $secret = $request->get('identifier');

        /** @var Doc $doc */
        $doc = $this->getDoctrine()
            ->getManager()
            ->getRepository('AppShedExtensionsSpreadsheetBundle:Doc')
            ->findOneBy(['itemsecret' => $secret]);

        if (!$doc) {
            $screen = new Screen('Error');
            $screen->addChild(new HTML('You must setup the extension before using it'));
            return (new Remote($screen))->getSymfonyResponse();
        }

        $address = $doc->getAddress();

        try {

            $document = $this->getDocument(
                $doc->getKey(),
                $this->getFilterString($doc->getFilters(), $request)
            );

            //This screen will have a list of the values in A column
            $screen = new Screen($document->getTitle());
            $worksheets = $document->getWorksheets();
            $worksheet = $worksheets[0];

            $lines = $worksheet->getListFeed()->getEntries();

            //For each row of the table
            foreach ($lines as $lineEntry) {
                $index = true;

                $lineColumns =$lineEntry->getValues();

                //Each of the columns of the row
                foreach ($lineColumns as $name => $value) {

                    //If the name of a column ends with a '-' then we dont show it
                    if (((strlen($name) - 1) == strpos($name, '-')) == false) {
                        if ($index == true) {
                            //This screen will have all the values across the row
                            $innerScreen = new Screen($value);

                            $link = new Link($value);
                            $screen->addChild($link);
                            $index = false;
                            $link->setScreenLink($innerScreen);
                        } else {
                            if (!empty($value)) {
                                $map = false;

                                if ($name == strtolower($address) ) {

                                    $geo = $this->geoService->getGeo($value);

                                    if ($geo) {
                                        $marker = new Marker($name, $value, $geo['lng'], $geo['lat']);

                                        $map = new Map($name);
                                        $map->addChild($marker);
                                    }
                                }

                                if ($map) {
                                    $link = new Link($value);
                                    $innerScreen->addChild($link);
                                    $link->setScreenLink($map);
                                } else {
                                    $innerScreen->addChild(new HTML($value));
                                }
                            }
                        }
                    }
                }
            }

            return (new Remote($screen))->getSymfonyResponse();
        } catch (\Exception $e) {
            $screen = new Screen('Error');
            $screen->addChild(new HTML('There was an error reading'));
            $screen->addChild(new Text($e->getMessage()));

            $this->logger->error(
                'Problem reading a spreadsheet',
                [
                    'exception' => $e
                ]
            );
            return (new Remote($screen))->getSymfonyResponse();
        }
    }

    private function getRowTitles($key)
    {
        $titles = [];

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

    private function getDocument($key, $filter = null)
    {
//        $query = new ListQuery();
//        $query->setSpreadsheetKey($key);
//        if ($filter) {
//            $query->setSpreadsheetQuery($filter);
//        }

        // need to test it where is used

        $listFeed = $this->getSpreadsheets()->getSpreadsheetById($key);

        return $listFeed;
    }

    private function getFilterString($filter, Request $request)
    {
        $filters = [];

        foreach ($filter as $option) {

            if ($option['filter'] == 'aroundme') {
                $filters[] = $this->getAroundMeQuery($option['value'], $request);
            } else {
                if (ctype_digit($option['value'])) {
                    $filters[] = $option['name'] . " " . $option['filter'] . " " . $option['value'] . ' ';
                } else {
                    if ($option['filter'] == 'like') {
                        $filters[] = $option['name'] . " " . $option['filter'] . ' %' . $option['value'] . '% ';
                    } else {
                        $filters[] = $option['name'] . " " . $option['filter'] . ' "' . $option['value'] . '" ';
                    }
                }
            }
        }

        $str = implode(' AND ', $filters);
        return $str;
    }

    private function getAroundMeQuery($distance, Request $request)
    {
        $center = [
            'lat' => $request->query->get('userlat', 0),
            'lng' => $request->query->get('userlng', 0)
        ];
        $bounds = $this->getBounds($center, $distance);
        $filters[] = 'lat > ' . $bounds['minLat'];
        $filters[] = 'lat < ' . $bounds['maxLat'];
        $filters[] = 'lng > ' . $bounds['minLng'];
        $filters[] = 'lng < ' . $bounds['maxLng'];

        return implode(' AND ', $filters);
    }

    private function getBounds($center, $radius)
    {
        $conv = $this->getConv($center);
        $bounces = [];

        $top = $this->getPointPosition($conv, $center, $radius, 0);
        $right = $this->getPointPosition($conv, $center, $radius, 90);
        $bottom = $this->getPointPosition($conv, $center, $radius, 180);
        $left = $this->getPointPosition($conv, $center, $radius, 270);
        $bounces['minLng'] = $left['lng'];
        $bounces['maxLng'] = $right['lng'];
        $bounces['minLat'] = $bottom['lat'];
        $bounces['maxLat'] = $top['lat'];
        return $bounces;
    }

    private function distanceOrt($position, $point, $limit = false)
    {
        $ra = M_PI / 180;
        $b = $position['lat'] * $ra;
        $c = $point['lat'] * $ra;
        $f = (2 * asin(
                    sqrt(
                        pow(sin(($b - $c) / 2), 2) + cos($b) * cos($c) * pow(
                            sin(($position['lng'] * $ra - $point['lng'] * $ra) / 2),
                            2
                        )
                    )
                )) * 6378137;

        if ($limit) {
            return $f <= $limit;
        } else {
            return $f;
        }
    }

    private function getConv($center)
    {
        return [
            'lat' => $this->distanceOrt(
                    $center,
                    ['lat' => ($center['lat'] + 0.1), 'lng' => ($center['lng'])]
                ) / 100,
            'lng' => $this->distanceOrt($center, ['lat' => $center['lat'], 'lng' => ($center['lng'] + 0.1)]) / 100
        ];
    }

    private function getPointPosition($conv, $center, $r, $angle)
    {
        $r = $r / 1000;
        return [
            'lat' => $center['lat'] + ($r / $conv['lat'] * cos($angle * M_PI / 180)),
            'lng' => $center['lng'] + ($r / $conv['lng'] * sin($angle * M_PI / 180)),
            'angle' => $angle
        ];
    }
}

<?php

namespace Sauladam\ShipmentTracker\Trackers;

use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use Sauladam\ShipmentTracker\Event;
use Sauladam\ShipmentTracker\Track;

class UPS extends AbstractTracker
{
    /**
     * @var string
     */
    protected $serviceEndpoint = 'http://wwwapps.ups.com/WebTracking/track';

    /**
     * @var string
     */
    protected $language = 'de';


    /**
     * @param $contents
     *
     * @return Track
     * @throws \Exception
     */
    protected function buildResponse($contents)
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($contents);
        $dom->preserveWhiteSpace = false;

        $domxpath = new DOMXPath($dom);

        return $this->getTrack($domxpath);
    }


    /**
     * Get the shipment status history.
     *
     * @param DOMXPath $xpath
     *
     * @return Track
     * @throws \Exception
     */
    protected function getTrack(DOMXPath $xpath)
    {
        $rows = $xpath->query("//table[@class='dataTable']//tr");

        if (!$rows) {
            throw new \Exception("Unable to parse UPS tracking data for [{$this->parcelNumber}].");
        }

        $track = new Track;

        $lastLocation = '';

        foreach ($rows as $index => $row) {
            if ($index == 0) {
                continue; // skip the heading row
            }

            $eventData = $this->parseRow($row, $xpath);

            if (!empty($eventData['location'])) {
                $lastLocation = $eventData['location'];
            } else {
                $eventData['location'] = $lastLocation;
            }

            $event = Event::fromArray($eventData);

            $track->addEvent($event);

            if (array_key_exists('recipient', $eventData)) {
                $track->setRecipient($eventData['recipient']);
            }
        }

        return $track->sortEvents();
    }


    /**
     * Parse the row of the history table.
     *
     * @param DOMElement $row
     * @param DOMXPath $xpath
     *
     * @return array
     */
    protected function parseRow(DOMElement $row, DOMXPath $xpath)
    {
        $rowData = [];

        $date = $time = '';
        $column = 0;

        foreach ($row->childNodes as $tableCell) {

            if ($tableCell->nodeName !== 'td') {
                continue;
            }

            $value = $this->getNodeValue($tableCell);

            switch ($column) {
                case 0:
                    $rowData['location'] = $value;
                    break;

                case 1:
                    $date = $value;
                    break;

                case 2:
                    $time = $value;
                    break;

                case 3:
                    $rowData['description'] = $value;
                    break;

                default:
                    break;
            }

            $column++;
        }

        $rowData['date'] = $this->getDate($date, $time);

        $status = $this->resolveState($rowData['description']);

        $rowData['status'] = $status;

        if ($status == Track::STATUS_DELIVERED && $recipient = $this->getRecipient($xpath)) {
            $rowData['recipient'] = $recipient;
        }

        return $rowData;
    }


    /**
     * Get the node value.
     *
     * @param DOMText|DOMNode $element
     *
     * @return string
     */
    protected function getNodeValue($element)
    {
        $value = trim($element->nodeValue);

        return preg_replace('/\s\s+/', ' ', $value);
    }


    /**
     * Parse the date from the given strings.
     *
     * @param $date
     * @param $time
     *
     * @return Carbon
     */
    protected function getDate($date, $time)
    {
        $time = $this->removeTimeZone($time);

        return Carbon::parse("$date $time");
    }


    /**
     * Remove timezone indications like "(ET)".
     *
     * @param string $time
     * @return string
     */
    protected function removeTimeZone($time)
    {
        return preg_replace('/\([A-Z]+\)/i', '', $time);
    }


    /**
     * Parse the recipient.
     *
     * @param DOMXPath $xpath
     *
     * @return null|string
     */
    protected function getRecipient(DOMXPath $xpath)
    {
        $nodes = $xpath->query("//fieldset//dl");

        foreach ($nodes as $node) {
            $descriptionTerm = $this->getFirstNonEmptyChildNodeValue($node);

            $describesRecipient = $this->startsWith('Received By:', $descriptionTerm)
                || $this->startsWith('Signed By:', $descriptionTerm)
                || $this->startsWith('Entgegengenommen von:', $descriptionTerm);

            if ($describesRecipient) {
                return $this->getLastNonEmptyChildNodeValue($node);
            }
        }

        return null;
    }


    /**
     * Get the first non empty child node value of the given element.
     *
     * @param DOMElement $element
     * @return null|string
     */
    protected function getFirstNonEmptyChildNodeValue(DOMElement $element)
    {
        foreach ($element->childNodes as $cn) {
            $value = $this->getNodeValue($cn);

            if (!empty($value)) {
                return $value;
            }
        }

        return null;
    }


    /**
     * Get the last non empty child node value of the given element.
     *
     * @param DOMElement $element
     * @return null|string
     */
    protected function getLastNonEmptyChildNodeValue(DOMElement $element)
    {
        if (!$element->hasChildNodes()) {
            return null;
        }

        $value = $this->getNodeValue($element->lastChild);

        if (!empty($value)) {
            return $value;
        }

        $element->removeChild($element->lastChild);

        return $this->getLastNonEmptyChildNodeValue($element);
    }


    /**
     * Check if the subject starts with the given string.
     *
     * @param string $start
     * @param string $subject
     * @return bool
     */
    protected function startsWith($start, $subject)
    {
        return strpos($subject, $start) === 0;
    }


    /**
     * Match a shipping status from the given description.
     *
     * @param $statusDescription
     *
     * @return string
     */
    protected function resolveState($statusDescription)
    {
        $statuses = [
            Track::STATUS_PICKUP => [
                'UPS Access Point™ possession',
                'Beim UPS Access Point™',
                'Delivered to UPS Access Point™',
                'An UPS Access Point™ zugestellt'
            ],
            Track::STATUS_DELIVERED => [
                'Delivered',
                'Zugestellt'
            ],
            Track::STATUS_IN_TRANSIT => [
                'Auftrag verarbeitet',
                'Ready for UPS',
                'Scan',
                'Out For Delivery',
                'receiver requested a hold for a future delivery date',
                'receiver was not available at the time of the first delivery attempt',
                'war beim 1. Zustellversuch nicht anwesend',
                'Adresse wurde korrigiert und die Zustellung neu terminiert',
                'The address has been corrected',
                'A final attempt will be made',
                'ltiger Versuch erfolgt',
                'Will deliver to a nearby UPS Access Point™ for customer pick up',
                'Zustellung wird zur Abholung durch Kunden an nahem UPS Access Point™ abgegeben',
                'Customer was not available when UPS attempted delivery',
            ],
            Track::STATUS_WARNING => [
                'attempting to obtain a new delivery address',
                'eine neue Zustelladresse für den Empf',
                'nderung für dieses Paket ist in Bearbeitung',
                'A delivery change for this package is in progress',
                'The receiver was not available at the time of the final delivery attempt',
            ],
            Track::STATUS_EXCEPTION => [
                'Exception',
                'Adressfehlers konnte die Sendung nicht zugestellt',
                'nger ist unbekannt',
                'The address is incomplete',
                'ist falsch',
                'is incorrect',
                'ltigen Zustellversuch nicht anwesend',
                'receiver was not available at the time of the final delivery attempt',
            ],
        ];

        foreach ($statuses as $status => $needles) {
            foreach ($needles as $needle) {
                if (strpos($statusDescription, $needle) !== false) {
                    return $status;
                }
            }
        }

        return Track::STATUS_UNKNOWN;
    }


    /**
     * Build the url for the given tracking number.
     *
     * @param string $trackingNumber
     * @param null $language
     * @param array $params
     *
     * @return string
     */
    public function trackingUrl($trackingNumber, $language = null, $params = [])
    {
        $language = $language ?: $this->language;

        $additionalParams = !empty($params) ? $params : $this->trackingUrlParams;

        $qry = http_build_query(array_merge([
            'loc' => $this->getLanguageQueryParam($language),
            'track' => 'yes',
            'trackNums' => $trackingNumber,
        ], $additionalParams));

        return $this->serviceEndpoint . '?' . $qry;
    }


    /**
     * Get the language value for the url query
     *
     * @param string $givenLanguage
     *
     * @return string
     */
    protected function getLanguageQueryParam($givenLanguage)
    {
        if ($givenLanguage == 'de') {
            return 'de_DE';
        }

        return 'en_US';
    }
}

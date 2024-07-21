<?php

use Swoole\Coroutine\System;

class SNMP_Swoole
{
    const PRINTER_TYPE_MONO  = 'mono printer';
    const PRINTER_TYPE_COLOR = 'color printer';

    const CARTRIDGE_COLOR_CYAN    = 'cyan';
    const CARTRIDGE_COLOR_MAGENTA = 'magenta';
    const CARTRIDGE_COLOR_YELLOW  = 'yellow';
    const CARTRIDGE_COLOR_BLACK   = 'black';

    const MARKER_SUPPLIES_UNAVAILABLE    = -1;
    const MARKER_SUPPLIES_UNKNOWN        = -2;
    const MARKER_SUPPLIES_SOME_REMAINING = -3;

    const SNMP_PRINTER_FACTORY_ID                     = '.1.3.6.1.2.1.1.1.0';
    const SNMP_PRINTER_SERIAL_NUMBER                  = '.1.3.6.1.2.1.43.5.1.1.17.1';
    const SNMP_PRINTER_VENDOR_NAME                    = '.1.3.6.1.2.1.43.9.2.1.8.1.1';
    const SNMP_NUMBER_OF_PRINTED_PAPERS               = '.1.3.6.1.2.1.43.10.2.1.4.1.1';
    const SNMP_MARKER_SUPPLIES_MAX_CAPACITY_SLOT_1    = '.1.3.6.1.2.1.43.11.1.1.8.1.1';
    const SNMP_MARKER_SUPPLIES_ACTUAL_CAPACITY_SLOT_1 = '.1.3.6.1.2.1.43.11.1.1.9.1.1';
    const SNMP_CARTRIDGE_COLOR_SLOT_1                 = '.1.3.6.1.2.1.43.12.1.1.4.1.1';

    private $snmpHost;
    private $snmpCommunity;

    public function __construct($host, $community)
    {
        $this->snmpHost = $host;
        $this->snmpCommunity = $community;
    }

    private function getSNMPString($oid)
    {
        return System::exec("snmpget -v2c -c {$this->snmpCommunity} {$this->snmpHost} $oid");
    }

    private function getSNMPValue($oid)
    {
        $result = $this->getSNMPString($oid);
        if ($result['code'] === 0) {
            $value = explode(':', $result['output'][0]);
            return trim(end($value));
        }
        return false;
    }

    private function getSNMPWalk($oid)
    {
        return System::exec("snmpwalk -v2c -c {$this->snmpCommunity} {$this->snmpHost} $oid");
    }

    public function getTypeOfPrinter()
    {
        $colorCartridgeSlot1 = $this->getSNMPValue(self::SNMP_CARTRIDGE_COLOR_SLOT_1);
        if ($colorCartridgeSlot1 !== false) {
            return (strtolower($colorCartridgeSlot1) === self::CARTRIDGE_COLOR_CYAN) ? self::PRINTER_TYPE_COLOR : self::PRINTER_TYPE_MONO;
        }
        return false;
    }

    public function isColorPrinter()
    {
        return $this->getTypeOfPrinter() === self::PRINTER_TYPE_COLOR;
    }

    public function isMonoPrinter()
    {
        return $this->getTypeOfPrinter() === self::PRINTER_TYPE_MONO;
    }

    public function getFactoryId()
    {
        return $this->getSNMPValue(self::SNMP_PRINTER_FACTORY_ID);
    }

    public function getVendorName()
    {
        return $this->getSNMPValue(self::SNMP_PRINTER_VENDOR_NAME);
    }

    public function getSerialNumber()
    {
        return $this->getSNMPValue(self::SNMP_PRINTER_SERIAL_NUMBER);
    }

    public function getNumberOfPrintedPapers()
    {
        return (int) $this->getSNMPValue(self::SNMP_NUMBER_OF_PRINTED_PAPERS);
    }

    public function getBlackCatridgeType()
    {
        if ($this->isColorPrinter()) {
            return $this->getSNMPValue(self::SNMP_CARTRIDGE_COLOR_SLOT_1);
        }
        if ($this->isMonoPrinter()) {
            return $this->getSNMPValue(self::SNMP_MARKER_SUPPLIES_MAX_CAPACITY_SLOT_1);
        }
        return false;
    }

    public function getSubUnitPercentageLevel($maxValueSNMPSlot, $actualValueSNMPSlot)
    {
        $max = $this->getSNMPValue($maxValueSNMPSlot);
        $actual = $this->getSNMPValue($actualValueSNMPSlot);

        if ($max === false || $actual === false) {
            return false;
        }

        if ((int) $actual <= 0) {
            return (int) $actual;
        } else {
            return ($actual / ($max / 100));
        }
    }

    public function getBlackTonerLevel()
    {
        if ($this->isColorPrinter()) {
            return $this->getSubUnitPercentageLevel(
                self::SNMP_MARKER_SUPPLIES_MAX_CAPACITY_SLOT_1,
                self::SNMP_MARKER_SUPPLIES_ACTUAL_CAPACITY_SLOT_1
            );
        } elseif ($this->isMonoPrinter()) {
            return $this->getSubUnitPercentageLevel(
                self::SNMP_MARKER_SUPPLIES_MAX_CAPACITY_SLOT_1,
                self::SNMP_MARKER_SUPPLIES_ACTUAL_CAPACITY_SLOT_1
            );
        }
        return false;
    }

    public function getAllSubUnitData()
    {
        $names        = explode("\n", $this->getSNMPWalk(self::SNMP_MARKER_SUPPLIES_MAX_CAPACITY_SLOT_1));
        $maxValues    = explode("\n", $this->getSNMPWalk(self::SNMP_MARKER_SUPPLIES_ACTUAL_CAPACITY_SLOT_1));
        $actualValues = explode("\n", $this->getSNMPWalk(self::SNMP_MARKER_SUPPLIES_MAX_CAPACITY_SLOT_1));

        $resultData = [];
        for ($i = 0; $i < count($names); $i++) {
            $resultData[] = [
                'name'            => str_replace('"', '', $names[$i]),
                'maxValue'        => $maxValues[$i],
                'actualValue'     => $actualValues[$i],
                'percentageLevel' => ((int)$actualValues[$i] >= 0) ? ($actualValues[$i] / ($maxValues[$i] / 100)) : null,
            ];
        }
        return $resultData;
    }

    protected function get($oid)
    {
        return Coroutine::create(function() use ($oid) {
            return snmpget($this->host, $this->community, $oid);
        });
    }

    protected function walk($oid)
    {
        return Coroutine::create(function() use ($oid) {
            return snmpwalk($this->host, $this->community, $oid);
        });
    }

    protected function getSNMPString($oid)
    {
        return Coroutine::create(function() use ($oid) {
            $result = snmpget($this->host, $this->community, $oid);
            if ($result === false) {
                return false;
            }
            return str_replace('STRING: ', '', $result);
        });
    }
}



<?php
/**
 * Invented lockers shaped like GET /pudo-points/by-courier and /closest.
 */
class Livraria_Locker_Fixtures {

    public static function sameday() {
        return array(
            array('id' => 'SD-1001', 'name' => 'easybox Exemplu Centru', 'address' => array('street' => 'Strada Exemplu', 'streetNo' => '10', 'city' => 'Cluj-Napoca', 'county' => 'Cluj', 'postalCode' => '400100'), 'capabilities' => array('clientVisible' => true)),
            array('id' => 'SD-1002', 'name' => 'easybox Ascuns', 'address' => array('city' => 'Cluj-Napoca', 'county' => 'Cluj'), 'capabilities' => array('clientVisible' => false)),
            array('id' => 'SD-1003', 'name' => 'easybox Plin', 'address' => array('city' => 'Brasov', 'county' => 'Brasov'), 'capabilities' => array('offerability' => array('offerable' => false, 'reason' => 'FULL'))),
            array('id' => 'SD-1004', 'name' => 'Alfa easybox Gara', 'address' => array('street' => 'Bulevardul Gării', 'streetNo' => '2', 'city' => 'Brasov', 'county' => 'Brasov', 'postalCode' => '500001')),
            array('id' => '', 'name' => 'fara id'),
            array('id' => 'SD-1006', 'name' => 'easybox Fara Oras', 'address' => array('street' => 'Strada Necunoscuta')),
        );
    }

    public static function dpd() {
        return array('data' => array(
            array('lockerId' => 'DPD-2001', 'name' => 'DPD Locker Exemplu Mall', 'address' => array('street' => 'Calea Exemplu', 'streetNo' => '5', 'city' => 'Iasi', 'county' => 'Iasi', 'postalCode' => '700001')),
        ));
    }

    public static function couriers() {
        return array(
            array('_id' => 'courier-sameday-id', 'slug' => 'sameday', 'name' => 'Sameday', 'activeLockers' => true),
            array('_id' => 'courier-dpd-id', 'slug' => 'DPD', 'name' => 'DPD', 'activeLockers' => true),
            array('_id' => 'courier-fan-id', 'slug' => 'fancourier', 'name' => 'FAN Courier', 'activeLockers' => false),
        );
    }
}

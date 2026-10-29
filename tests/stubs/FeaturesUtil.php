<?php
// Stand-in for WooCommerce's FeaturesUtil: records each declaration.
namespace Automattic\WooCommerce\Utilities;

class FeaturesUtil {
    public static $declared = array();

    public static function declare_compatibility($feature, $plugin_file, $positive_compatibility = true) {
        self::$declared[] = array($feature, $plugin_file, $positive_compatibility);
        return true;
    }
}

<?php
// Stand-in: its presence tells the plugin that HPOS order screens exist.
namespace Automattic\WooCommerce\Internal\DataStores\Orders;

if (!class_exists(OrdersTableDataStore::class)) {
    class OrdersTableDataStore {
    }
}

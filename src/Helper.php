<?php
/**
 * Copyright © 2017 SeQura Engineering. All rights reserved.
 */


namespace Sequra\PhpClient;

class Helper
{
    public static function isConsistentCart($cart)
    {
        $totals = self::totals($cart);

        return $cart['order_total_without_tax'] == $totals['without_tax'] && $cart['order_total_with_tax'] == $totals['with_tax'];
    }

    public static function totals($cart)
    {
        $total_without_tax = $total_with_tax = 0;
        foreach ($cart['items'] as $item) {
            $total_without_tax += isset($item['total_without_tax']) ? $item['total_without_tax'] : 0;
            $total_with_tax += isset($item['total_with_tax']) ? $item['total_with_tax'] : 0;
        }

        return array('without_tax' => $total_without_tax, 'with_tax' => $total_with_tax);
    }

    public static function removeNulls($data)
    {
        foreach ($data as $key => $value) {
            if (is_null($value)) {
                unset($data[$key]);
            } else {
                if (is_array($value)) {
                    $data[$key] = self::removeNulls($value);
                }
            }
        }

        return $data;
    }

    public static function notNull($value1, $value2)
    {
        return is_null($value1) ? $value2 : $value1;
    }
}
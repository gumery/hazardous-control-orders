<?php

/**
 * @file Order.php
 * @brief 对外提供API服务
 *
 * @author PiHiZi <pihizi@msn.com>
 *
 * @version 0.1.0
 * @date 2016-01-11
 */
namespace Gini\Controller\API\HazardousControl;

class Orders extends \Gini\Controller\API\HazardousControl\Base
{
    private static $allowedTypes = [
        'group' => 'group_id',
        'vendor' => 'vendor_id',
        'type' => 'product_type',
    ];

    private static function _getOPTableName()
    {
        return \Gini\Config::get('hazardous-control-orders.table') ?: '_hazardous_control_order_product';
    }
    /**
     * @brief 获取危化品搜索使用的token
     *
     * @param $params
     *   [
     *       'type'=> 'group | vendor | type'
     *   ]
     *
     * @return
     *   [
     *       'token'=> string,
     *       'total'=> int
     *   ]
     */
    public function actionSearchHazardousOrders(array $params)
    {
        $result = [
            'total' => 0,
            'token' => '',
        ];
        $type = $params['type'];
        if (!isset(self::$allowedTypes[$type])) {
            return $result;
        }

        $db = \Gini\DataBase::db();
        $tableName = self::_getOPTableName();
        $token = md5(J($params));
        $sql = 'SELECT COUNT(*) FROM :tablename GROUP BY :groupby';
        $total = $db->query($sql, [
            ':tablename' => $tableName,
            ':groupby' => self::$allowedTypes[$type],
        ])->count();

        $_SESSION[$token] = $params;

        $result = [
            'total' => $total,
            'token' => $token,
        ];

        return $result;
    }

    /**
     * @brief 获取危化品的汇总信息
     *
     * @param $token
     * @param $start
     * @param $perpage
     *
     * @return
     *   [
     *       [
     *           'totalOrders'=> // 有效订单总数
     *           'totalPrices'=> // 有效订单总价
     *           'transferredOrders'=> // 已付款订单总数
     *           'transferredPrices'=> // 已付款订单总价
     *           'paidOrders'=> // 已结算订单总数
     *           'paidPrices'=> // 已结算订单总价
     *           'products'=> [// 第一屏相关的商品列表
     *               [
     *                   'name'=> // 商品名称
     *                   'quantity'=> //总量
     *                   'price'=> // 总价
     *               ],
     *               ...
     *           ]
     *       ],
     *       ...
     *   ]
     */
    public function actionGetHazardousOrders($token, $start = 0, $perpage = 25)
    {
        $result = [];
        $params = $_SESSION[$token];
        if (empty($params)) {
            return $result;
        }

        $start = is_numeric($start) ? $start : 0;
        $perpage = min($perpage, 25);

        $type = $params['type'];
        $groupBy = self::$allowedTypes[$type];
        $tableName = self::_getOPTableName();
        $db = \Gini\DataBase::db();

        $sql = "SELECT :groupby, COUNT(order_id) AS count_order, SUM(order_price) AS sum_order_price, SUM(IF(order_status=:statustransferred, 1, 0)) AS count_transferred_order, SUM(IF(order_status=:statustransferred, order_price, 0)) AS sum_transferred_order_price, SUM(IF(order_status=:statuspaid, 1, 0)) AS count_paid_order, SUM(IF(order_status=:statuspaid, order_price, 0)) AS sum_paid_order_price FROM :tablename GROUP BY :groupby LIMIT {$start},{$perpage}";
        $rows = $db->query(strtr($sql, [
            ':tablename' => $db->quoteIdent($tableName),
            ':groupby' => $db->quoteIdent($groupBy),
            ':statustransferred' => $db->quote(\Gini\ORM\Order::STATUS_TRANSFERRED),
            ':statuspaid' => $db->quote(\Gini\ORM\Order::STATUS_PAID),
        ]))->rows();
        foreach ($rows as $row) {
            $result[] = [
                'totalOrders' => $row->count_order,
                'totalPrices' => $row->sum_order_price,
                'transferredOrders' => $row->count_transferred_order,
                'transferredPrices' => $row->sum_transferred_order_price,
                'paidOrders' => $row->count_paid_order,
                'paidPrices' => $row->sum_paid_order_price,
                'products' => $this->_getProducts($groupBy, $row->$groupBy),
            ];
        }

        return $result;
    }

    /**
     * @brief 获取危化品商品搜索使用的token
     *
     * @param $params
     *   [
     *       'type'=> 'group | vendor | type'
     *       'type_value'=> ''
     *   ]
     *
     * @return
     *   [
     *       'token'=> string,
     *       'total'=> int
     *   ]
     */
    public function actionSearchHazardousProducts(array $params)
    {
        $result = [
            'total' => 0,
            'token' => '',
        ];
        $type = $params['type'];
        if (!isset(self::$allowedTypes[$type])) {
            return $result;
        }

        $db = \Gini\DataBase::db();
        $tableName = self::_getOPTableName();
        $token = md5(J($params));

        $sql = 'SELECT COUNT(*) FROM :tablename WHERE :col=:value GROUP BY cas_no';
        $total = $db->query(strtr($sql, [
            ':tableName' => $db->quoteIdent($tableName),
            ':col' => $db->quoteIdent(self::$allowedTypes[$type]),
            ':value' => $db->quote($params['type_value']),
        ]))->count();

        $_SESSION[$token] = $params;

        $result = [
            'total' => $total,
            'token' => $token,
        ];

        return $result;
    }

    /**
     * @brief 获取符合条件的订单商品的列表
     *
     * @param $token
     * @param $start
     * @param $perpage
     *
     * @return
     *   [
     *       [
     *           'name'=> //商品名称
     *           'quantity'=> // 商品总量
     *           'price'=> // 商品总价
     *       ],
     *       ...
     *   ]
     */
    public function actionGetHazardousProducts($token, $start = 0, $perpage = 25)
    {
        $result = [];
        $params = $_SESSION[$token];
        if (empty($params)) {
            return $result;
        }

        $start = is_numeric($start) ? $start : 0;
        $perpage = min($perpage, 25);

        $type = $params['type'];
        $type = self::$allowedTypes[$type];
        $value = $params['type_value'];

        $result = $this->_getProducts($type, $value, $start, $perpage);

        return $result;
    }

    private function _getProducts($col, $value, $start = 0, $perpage = 5)
    {
        $tableName = self::_getOPTableName();
        $db = \Gini\DataBase::db();
        $sql = "SELECT cas_no FROM :tablename WHERE :col=:value GROUP BY cas_no LIMIT {$start},{$perpage}";
        $rows = $db->query(strtr($sql, [
            ':tablename' => $db->quoteIdent($tableName),
            ':col' => $db->quoteIdent($col),
            ':value' => $db->quote($value),
        ]))->rows();

        $result = [];
        foreach ($rows as $row) {
            $tmpStart = 0;
            $tmpPerpage = 10;
            $tmpName = '';
            $tmpCount = 0;
            $tmpPrices = 0;
            while (true) {
                $sql = "SELECT product_name,product_package,product_quantity,product_total_price FROM :tablename WHERE :col=:value AND cas_no=:casno LIMIT {$tmpStart},{$tmpPerpage}";
                $tmpRows = $db->query(strtr($sql, [
                    ':tablename' => $db->quoteIdent($tableName),
                    ':col' => $db->quoteIdent($col),
                    ':value' => $db->quote($value),
                    ':casno' => $db->quote($row->cas_no),
                ]))->rows();
                if (!count($tmpRows)) {
                    break;
                }
                $tmpStart += $tmpPerpage;
                foreach ($tmpRows as $tmpRow) {
                    $tmpName = $tmpName ?: $tmpRow->product_name;
                    $tmpCount = $this->_computeSum($tmpCount, $tmpRow->product_package, $tmpRow->product_quantity);
                    $tmpPrices += $tmpRow->product_total_price;
                }
            }
            $result[] = [
                'name' => $tmpName,
                'quantity' => round($tmpCount, 2),
                'price' => round($tmpPrices, 2),
            ];
        }

        return $result;
    }

    private function _computeSum($count, $package, $quantity)
    {
        // TODO 需要考虑不同package的问题
        return $count + $package * $quantity;
    }
}

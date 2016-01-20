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
     *       'from'=>
     *       'to'=>
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
        $from = $params['from'];
        $to = $params['to'];
        $justHazardous = !!$params['just_hazardous'] ?: false;
        if (!isset(self::$allowedTypes[$type])) {
            return $result;
        }
        list($from, $to) = $this->_challengeFromTo($from, $to);

        $db = \Gini\DataBase::db();
        $tableName = self::_getOPTableName();
        $token = md5(J($params));
        if ($justHazardous) {
            $sql = 'SELECT COUNT(*) FROM :tablename WHERE order_mtime BETWEEN :from AND :to AND product_type IN (\'hazardous\', \'drug_precursor\', \'highly_toxic\') GROUP BY :groupby';
        }
        else {
            $sql = 'SELECT COUNT(*) FROM :tablename WHERE order_mtime BETWEEN :from AND :to GROUP BY :groupby';
        }
        $total = $db->query($sql, [
            ':tablename' => $tableName,
            ':groupby' => self::$allowedTypes[$type],
        ], [
            ':from'=> $from,
            ':to'=> $to
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
     *           'data'=> [// 第一屏相关的商品列表
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
        $from = $params['from'];
        $to = $params['to'];
        $justHazardous = !!$params['just_hazardous'] ?: false;
        list($from, $to) = $this->_challengeFromTo($from, $to);
        $groupBy = self::$allowedTypes[$type];
        $tableName = self::_getOPTableName();
        $db = \Gini\DataBase::db();

        if ($justHazardous) {
            $sql = "SELECT product_type, group_id, group_name, vendor_id, vendor_name FROM :tablename WHERE order_mtime BETWEEN :from AND :to AND product_type IN ('hazardous', 'drug_precursor', 'highly_toxic') GROUP BY :groupby LIMIT {$start},{$perpage}";
        }
        else {
            $sql = "SELECT product_type, group_id, group_name, vendor_id, vendor_name FROM :tablename WHERE order_mtime BETWEEN :from AND :to GROUP BY :groupby LIMIT {$start},{$perpage}";
        }
        $rows = $db->query(strtr($sql, [
            ':tablename' => $db->quoteIdent($tableName),
            ':from'=> $db->quote($from),
            ':to'=> $db->quote($to),
            ':groupby' => $db->quoteIdent($groupBy),
        ]))->rows();

        foreach ($rows as $row) {
            switch ($type) {
            case 'type':
                $title = $row->product_type;
                break;
            case 'group':
                $title = $row->group_name;
                break;
            case 'vendor':
                $title = $row->vendor_name;
                break;
            }
            $myValid = $this->_getValidInfo($db, $tableName, $groupBy, $row, $from, $to, $justHazardous);
            $myTransferred = $this->_getTransferredInfo($db, $tableName, $groupBy, $row, $from, $to, $justHazardous);
            $myPaid = $this->_getPaidInfo($db, $tableName, $groupBy, $row, $from, $to, $justHazardous);
            $myCanceled = $this->_getCanceledInfo($db, $tableName, $groupBy, $row, $from, $to, $justHazardous);
            $myResult = [
                'type'=> $type,
                'value'=> $row->$groupBy,
                'title'=> $title,
                'data' => !$this->_allowShowDatas($type, $row->product_type) ? [] : $this->_getProducts($groupBy, $row->$groupBy, 0, 5, $from, $to, $justHazardous),
                'totalOrders' => $myValid[0],
                'totalPrices' => $myValid[1],
                'transferredOrders' => $myTransferred[0],
                'transferredPrices' => $myTransferred[1],
                'paidOrders' => $myPaid[0],
                'paidPrices' => $myPaid[1],
                'canceledOrders' => $myCanceled[0],
                'canceledPrices' => $myCanceled[1],
            ];
            $result[] = $myResult;
        }

        $totalValid = $this->_getTotalValidInfo($db, $tableName, $from, $to, $justHazardous);
        $totalTransferred = $this->_getTotalTransferredInfo($db, $tableName, $from, $to, $justHazardous);
        $totalPaid = $this->_getTotalPaidInfo($db, $tableName, $from, $to, $justHazardous);
        $totalCanceled = $this->_getTotalCanceledInfo($db, $tableName, $from, $to, $justHazardous);

        $data = [
            'totalOrders'=> $totalValid[0],
            'totalPrices'=> $totalValid[1],
            'transferredOrders'=> $totalTransferred[0],
            'transferredPrices'=> $totalTransferred[1],
            'paidOrders'=> $totalPaid[0],
            'paidPrices'=> $totalPaid[1],
            'canceledOrders'=> $totalCanceled[0],
            'canceledPrices'=> $totalCanceled[1],
            'data'=> $result
        ];

        return $data;
    }

    /**
     * @brief 获取危化品商品搜索使用的token
     *
     * @param $params
     *   [
     *       'type'=> 'group | vendor | type'
     *       'type_value'=> '',
     *       'from'=>
     *       'to'=>
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
        $value = $params['type_value'];
        $from = $params['from'];
        $to = $params['to'];
        $showAllProducts = $params['show_all'] ?: false;
        if (!isset(self::$allowedTypes[$type])) {
            return $result;
        }

        if (!$this->_allowShowDatas($type, $value)) {
            return $result;
        }

        list($from, $to) = $this->_challengeFromTo($from, $to);
        $db = \Gini\DataBase::db();
        $tableName = self::_getOPTableName();
        $token = md5(J($params));

        if (!$showAllProducts) {
            $sql = 'SELECT COUNT(*) FROM :tablename WHERE :col=:value AND product_type IN (\'hazardous\', \'drug_precursor\', \'highly_toxic\') AND order_mtime BETWEEN :from AND :to GROUP BY cas_no';
        }
        else {
            $sql = 'SELECT COUNT(*) FROM :tablename WHERE :col=:value AND order_mtime BETWEEN :from AND :to GROUP BY cas_no';
        }
        $total = $db->query(strtr($sql, [
            ':tablename' => $db->quoteIdent($tableName),
            ':col' => $db->quoteIdent(self::$allowedTypes[$type]),
            ':value' => $db->quote($params['type_value']),
            ':from'=> $db->quote($from),
            ':to'=> $db->quote($to)
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
        $from = $params['from'];
        $to = $params['to'];
        $showAllProducts = $params['show_all'] ?: false;
        list($from, $to) = $this->_challengeFromTo($from, $to);

        $result = $this->_getProducts($type, $value, $start, $perpage, $from, $to, !$showAllProducts);

        return $result;
    }

    private function _getProducts($col, $value, $start = 0, $perpage = 5, $from=null, $to=null, $justHazardous=false)
    {
        $tableName = self::_getOPTableName();
        $db = \Gini\DataBase::db();
        if ($justHazardous) {
            $sql = "SELECT id,cas_no,product_type FROM :tablename WHERE :col=:value AND cas_no!='' AND order_mtime BETWEEN :from AND :to AND product_type IN ('hazardous', 'drug_precursor', 'highly_toxic') GROUP BY cas_no ORDER BY product_type desc LIMIT {$start},{$perpage}";
        }
        else {
            $sql = "SELECT id,cas_no,product_type FROM :tablename WHERE :col=:value AND cas_no!='' AND order_mtime BETWEEN :from AND :to GROUP BY cas_no ORDER BY product_type desc LIMIT {$start},{$perpage}";
        }
        $rows = $db->query(strtr($sql, [
            ':tablename' => $db->quoteIdent($tableName),
            ':col' => $db->quoteIdent($col),
            ':value' => $db->quote($value),
            ':from'=> $db->quote($from),
            ':to'=> $db->quote($to)
        ]))->rows();

        $result = [];
        foreach ($rows as $row) {
            $tmpStart = 0;
            $tmpPerpage = 100;
            $tmpName = '';
            $tmpCount = 0;
            $tmpPrices = 0;
            while (true) {
                if ($justHazardous) {
                    $sql = "SELECT product_name,product_package,product_quantity,product_total_price FROM :tablename WHERE order_mtime BETWEEN :from AND :to AND product_type IN ('hazardous', 'drug_precursor', 'highly_toxic') AND :col=:value AND cas_no=:casno AND order_status!=:statuscanceled  LIMIT {$tmpStart},{$tmpPerpage}";
                }
                else {
                    $sql = "SELECT product_name,product_package,product_quantity,product_total_price FROM :tablename WHERE order_mtime BETWEEN :from AND :to AND :col=:value AND cas_no=:casno AND order_status!=:statuscanceled  LIMIT {$tmpStart},{$tmpPerpage}";
                }
                $tmpRows = $db->query(strtr($sql, [
                    ':tablename' => $db->quoteIdent($tableName),
                    ':col' => $db->quoteIdent($col),
                    ':value' => $db->quote($value),
                    ':casno' => $db->quote($row->cas_no),
                    ':statuscanceled'=> $db->quote(\Gini\ORM\Order::STATUS_CANCELED),
                    ':from'=> $db->quote($from),
                    ':to'=> $db->quote($to)
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
            if ($tmpName) {
                $result[] = [
                    'name' => $tmpName,
                    'quantity' => $tmpCount,
                    'price' => round($tmpPrices, 2),
                    'type' => $row->product_type
                ];
            }
        }
        return $result;
    }

    private function _allowShowDatas($type, $productType=null)
    {
        // if ($type!='type') {
        //     return false;
        // }
        if ($type=='type' && !in_array($productType, [
            'hazardous',
            'drug_precursor',
            'highly_toxic'
        ])) {
            return false;
        }
        return true;
    }

    private function _computeSum($count, $package, $quantity)
    {
        $a = $count;
        $b = $package * $quantity . $this->_getUnit($package);
        $result = $this->_convert2SameUnit($a, $b);
        if (!$result) {
            return $a;
        }
        list($a, $b, $unit) = $result;
        return round($a + $b, 2) . $unit;
    }

    private function _convert2SameUnit($a, $b)
    {
        $a = $a ?: 0;
        $b = $b ?: 0;
        $pattern = '/^(-?\d+(?:\.\d+)?)([a-z]+)?$/i';
        if (!preg_match($pattern, trim($a), $aMatches)) {
            return;
        }
        $pattern = '/^(-?\d+(?:\.\d+)?)([a-z]+)?$/i';
        if (!preg_match($pattern, trim($b), $bMatches)) {
            return;
        }

        $aUnit = $aMatches[2];
        $bUnit = $bMatches[2];
        $unit = $aUnit ? $aUnit : ($bUnit ?: '');
        $a = round($aMatches[1] * $this->_u2u($aUnit?:$unit, $unit), 2);
        $b = round($bMatches[1] * $this->_u2u($bUnit?:$unit, $unit), 2);

        return [$a, $b, strtolower($unit)];
    }

    private function _getUnitList($unit=null)
    {
        $data = [
            [
                'ul'=> 1000000,
                'μl'=> 1000000,
                'ml'=> 1000,
                'cl'=> 100,
                'dl'=> 10,
                'l'=>1
            ],
            [
                'ug'=> 1000000000,
                'μg'=> 1000000000,
                'mg'=> 1000000,
                'g'=> 1000,
                'kg'=>1
            ]
        ];

        if ($unit) {
            $unit = strtolower($unit);
            foreach ($data as $us) {
                if (isset($us[$unit])) {
                    return $us;
                }
            }
        }

        return $data;
    }

    private function _u2u($from, $to)
    {
        $from = strtolower($from);
        $to = strtolower($to);
        $data = $this->_getUnitList($from);
        if (isset($data[$from]) && isset($data[$to])) {
            return $data[$to] / $data[$from];
        }
        return 1;
    }

    private function _formatUnit($data, $defaultUnit=null)
    {
        if (!$data) return $data;
        $unit=$this->_getUnit($data) ?: $defaultUnit;
        if (!$unit) {
            return $data;
        }
        $units = $this->_getUnitList($unit);
        if (empty($units)) {
            return $data;
        }
        foreach ($units as $u=>$v) {
            $tmp = round($data * $this->_u2u($unit, $u), 2) . strtolower($u);
            if (strlen($tmp) < strlen($data)) {
                $data= $tmp;
                $unit = $u;
            }
        }

        if (!$this->_getUnit($data)) {
            $data = $data . strtolower($unit);
        }
        return $data;
    }

    private function _getUnit($string)
    {
        if (preg_match('/([a-zA-Z]+)$/', trim($string), $matches)) {
            return $matches[1];
        }
        return '';
    }

    private function _challengeFromTo($from, $to)
    {
        $min = strtotime('1998-01-01 00:00:00');
        $max = time();
        $from = @strtotime($from);
        $to = @strtotime($to);
        $from = ($from && $from>=$min && $from<=$max) ? $from : $min;
        $to = ($to && $to>=$min && $to<=$max) ? $to : $max;
        return [
            date('Y-m-d H:i:s', min($from, $to)),
            date('Y-m-d 23:59:59', max($from, $to))
        ];
    }

    // 求有效总数信息
    private function _getValidInfo($db, $tableName, $groupBy, $row, $from, $to, $justHazardous=false)
    {
        if ($justHazardous) {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE :groupby=:groupbyvalue AND product_type IN ('hazardous', 'drug_precursor', 'highly_toxic') AND order_mtime BETWEEN :from AND :to AND order_status!=:statuscanceled GROUP BY order_id) T1";
        }
        else {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE :groupby=:groupbyvalue AND order_mtime BETWEEN :from AND :to AND order_status!=:statuscanceled GROUP BY order_id) T1";
        }
        $row = $db->query(strtr($sql, [
            ':tablename' => $db->quoteIdent($tableName),
            ':groupby' => $db->quoteIdent($groupBy),
            ':groupbyvalue' => $db->quote($row->$groupBy),
            ':statuscanceled' => $db->quote(\Gini\ORM\Order::STATUS_CANCELED),
            ':from'=> $db->quote($from),
            ':to'=> $db->quote($to)
        ]))->row();
        return [$row->ct?:0, $row->op?:0];
    }

    // 求全部有效总数信息
    private function _getTotalValidInfo($db, $tableName, $from, $to, $justHazardous=false)
    {
        if ($justHazardous) {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE product_type IN ('hazardous', 'drug_precursor', 'highly_toxic') AND order_mtime BETWEEN :from AND :to AND order_status!=:statuscanceled GROUP BY order_id) T1";
        }
        else {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE order_mtime BETWEEN :from AND :to AND order_status!=:statuscanceled GROUP BY order_id) T1";
        }
        $row = $db->query(strtr($sql, [
            ':tablename' => $db->quoteIdent($tableName),
            ':statuscanceled' => $db->quote(\Gini\ORM\Order::STATUS_CANCELED),
            ':from'=> $db->quote($from),
            ':to'=> $db->quote($to)
        ]))->row();
        return [$row->ct?:0, $row->op?:0];
    }

    // 求已付款总数信息
    private function _getTransferredInfo($db, $tableName, $groupBy, $row, $from, $to, $justHazardous=false)
    {
        if ($justHazardous) {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE :groupby=:groupbyvalue AND product_type IN ('hazardous', 'drug_precursor', 'highly_toxic') AND order_mtime BETWEEN :from AND :to AND order_status=:statustransferred GROUP BY order_id) T1";
        }
        else {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE :groupby=:groupbyvalue AND order_mtime BETWEEN :from AND :to AND order_status=:statustransferred GROUP BY order_id) T1";
        }
        $row = $db->query(strtr($sql, [
            ':tablename' => $db->quoteIdent($tableName),
            ':groupby' => $db->quoteIdent($groupBy),
            ':groupbyvalue' => $db->quote($row->$groupBy),
            ':statustransferred' => $db->quote(\Gini\ORM\Order::STATUS_TRANSFERRED),
            ':from'=> $db->quote($from),
            ':to'=> $db->quote($to)
        ]))->row();
        return [$row->ct?:0, $row->op?:0];
    }

    // 求已付款总数信息
    private function _getTotalTransferredInfo($db, $tableName, $from, $to, $justHazardous=false)
    {
        if ($justHazardous) {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE product_type IN ('hazardous', 'drug_precursor', 'highly_toxic') AND order_mtime BETWEEN :from AND :to AND order_status=:statustransferred GROUP BY order_id) T1";
        }
        else {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE order_mtime BETWEEN :from AND :to AND order_status=:statustransferred GROUP BY order_id) T1";
        }
        $row = $db->query(strtr($sql, [
            ':tablename' => $db->quoteIdent($tableName),
            ':statustransferred' => $db->quote(\Gini\ORM\Order::STATUS_TRANSFERRED),
            ':from'=> $db->quote($from),
            ':to'=> $db->quote($to)
        ]))->row();
        return [$row->ct?:0, $row->op?:0];
    }

    // 求已结算总数信息
    private function _getPaidInfo($db, $tableName, $groupBy, $row, $from, $to, $justHazardous=false)
    {
        if ($justHazardous) {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE :groupby=:groupbyvalue AND product_type IN ('hazardous', 'drug_precursor', 'highly_toxic') AND order_mtime BETWEEN :from AND :to AND order_status=:statuspaid GROUP BY order_id) T1";
        }
        else {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE :groupby=:groupbyvalue AND order_mtime BETWEEN :from AND :to AND order_status=:statuspaid GROUP BY order_id) T1";
        }
        $row = $db->query(strtr($sql, [
            ':tablename' => $db->quoteIdent($tableName),
            ':groupby' => $db->quoteIdent($groupBy),
            ':groupbyvalue' => $db->quote($row->$groupBy),
            ':statuspaid' => $db->quote(\Gini\ORM\Order::STATUS_PAID),
            ':from'=> $db->quote($from),
            ':to'=> $db->quote($to)
        ]))->row();
        return [$row->ct?:0, $row->op?:0];
    }

    // 求已结算总数信息
    private function _getTotalPaidInfo($db, $tableName, $from, $to, $justHazardous=false)
    {
        if ($justHazardous) {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE product_type IN ('hazardous', 'drug_precursor', 'highly_toxic') AND order_mtime BETWEEN :from AND :to AND order_status=:statuspaid GROUP BY order_id) T1";
        }
        else {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE order_mtime BETWEEN :from AND :to AND order_status=:statuspaid GROUP BY order_id) T1";
        }
        $row = $db->query(strtr($sql, [
            ':tablename' => $db->quoteIdent($tableName),
            ':statuspaid' => $db->quote(\Gini\ORM\Order::STATUS_PAID),
            ':from'=> $db->quote($from),
            ':to'=> $db->quote($to)
        ]))->row();
        return [$row->ct?:0, $row->op?:0];
    }

    // 求已取消总数信息
    private function _getCanceledInfo($db, $tableName, $groupBy, $row, $from, $to, $justHazardous=false)
    {
        if ($justHazardous) {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE :groupby=:groupbyvalue AND product_type IN ('hazardous', 'drug_precursor', 'highly_toxic') AND order_mtime BETWEEN :from AND :to AND order_status=:statuscanceled GROUP BY order_id) T1";
        }
        else {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE :groupby=:groupbyvalue AND order_mtime BETWEEN :from AND :to AND order_status=:statuscanceled GROUP BY order_id) T1";
        }
        $row = $db->query(strtr($sql, [
            ':tablename' => $db->quoteIdent($tableName),
            ':groupby' => $db->quoteIdent($groupBy),
            ':groupbyvalue' => $db->quote($row->$groupBy),
            ':statuscanceled' => $db->quote(\Gini\ORM\Order::STATUS_CANCELED),
            ':from'=> $db->quote($from),
            ':to'=> $db->quote($to)
        ]))->row();
        return [$row->ct?:0, $row->op?:0];
    }

    // 求已取消总数信息
    private function _getTotalCanceledInfo($db, $tableName, $from, $to, $justHazardous=false)
    {
        if ($justHazardous) {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE product_type IN ('hazardous', 'drug_precursor', 'highly_toxic') AND order_mtime BETWEEN :from AND :to AND order_status=:statuscanceled GROUP BY order_id) T1";
        }
        else {
            $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM (SELECT order_id,order_price FROM :tablename WHERE order_mtime BETWEEN :from AND :to AND order_status=:statuscanceled GROUP BY order_id) T1";
        }
        $row = $db->query(strtr($sql, [
            ':tablename' => $db->quoteIdent($tableName),
            ':statuscanceled' => $db->quote(\Gini\ORM\Order::STATUS_CANCELED),
            ':from'=> $db->quote($from),
            ':to'=> $db->quote($to)
        ]))->row();
        return [$row->ct?:0, $row->op?:0];
    }
}

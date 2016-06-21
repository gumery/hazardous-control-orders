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
        if (!isset(self::$allowedTypes[$type])) {
            return $result;
        }

        $challenged = $this->_challengeFromTo($from, $to);
        if ($challenged===false) {
            return $result;
        }

        $newParams = [
            'type'=> $type,
            'conditions'=> []
        ];

        if ($challenged) {
            list($from, $to) = $challenged;
            $newParams['conditions']['from'] = $from;
            $newParams['conditions']['to'] = $to;
        }
        if (!empty($params['allowed_product_types']) && is_array($params['allowed_product_types'])) {
            $newParams['conditions']['product_types'] = $params['allowed_product_types'];
        }
        if (isset($params['q']) && trim($params['q'])!=='') {
            $qCondition = $this->getQCondition(trim($params['q']), $type);
            if ($qCondition) {
                $newParams['conditions']['q'] = $qCondition;
            }
        }

        $token = md5(J($params));
        $params = $newParams;

        $db = \Gini\DataBase::db();
        $tableName = self::_getOPTableName();
        $this->select('COUNT(DISTINCT order_id)', $tableName);
        if (isset($params['conditions']['from']) && isset($params['conditions']['to'])) {
            $this->where('order_mtime', 'between', $params['conditions']['from'], $params['conditions']['to']);
        }
        if (isset($params['conditions']['product_types'])) {
            $this->where('product_type', 'in', $params['conditions']['product_types']);
        }
        if (isset($params['conditions']['q'])) {
            $this->where($params['conditions']['q']);
        }
        $sql = $this->groupBy(self::$allowedTypes[$type])->getSQL();
        $total = $db->query($sql)->count();

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
        $groupBy = self::$allowedTypes[$type];

        $tableName = self::_getOPTableName();
        $db = \Gini\DataBase::db();
        $this->select('product_type, group_id, group_name, vendor_id, vendor_name', $tableName);
        if (isset($params['conditions']['product_types'])) {
            $this->where('product_type', 'in', $params['conditions']['product_types']);
        }
        if (isset($params['conditions']['from']) && isset($params['conditions']['to'])) {
            $this->where('order_mtime', 'between', $params['conditions']['from'], $params['conditions']['to']);
        }
        if (isset($params['conditions']['q'])) {
            $this->where($params['conditions']['q']);
        }
        $sql = $this->groupBy($groupBy)->limit($start, $perpage)->getSQL();
        $query = $db->query($sql);
        $rows = $query ? $query->rows() : [];

        switch ($type) {
        case 'type':
            $titleCol = 'product_type';
            break;
        case 'group':
            $titleCol = 'group_name';
            break;
        case 'vendor':
            $titleCol = 'vendor_name';
            break;
        }
        $productConditions = $params['conditions'];
        $productConditions['product_types'] = [
            'hazardous',
            'drug_precursor',
            'highly_toxic'
        ];
        foreach ($rows as $row) {
            $myValid = $this->_getTotalInfo('valid', $params['conditions'], $groupBy, $row->$groupBy);
            $myTransferred = $this->_getTotalInfo('transferred', $params['conditions'], $groupBy, $row->$groupBy);
            $myPaid = $this->_getTotalInfo('paid', $params['conditions'], $groupBy, $row->$groupBy);
            $myCanceled = $this->_getTotalInfo('canceled', $params['conditions'], $groupBy, $row->$groupBy);
            $myResult = [
                'type'=> $type,
                'value'=> $row->$groupBy,
                'title'=> $row->$titleCol,
                'data' => !$this->_allowShowDatas($type, $row->product_type) ? [] : $this->_getProducts($groupBy, $row->$groupBy, $productConditions, 0, 5),
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

        $totalValid = $this->_getTotalInfo('valid', $params['conditions']);
        $totalTransferred = $this->_getTotalInfo('transferred', $params['conditions']);
        $totalPaid = $this->_getTotalInfo('paid', $params['conditions']);
        $totalCanceled = $this->_getTotalInfo('canceled', $params['conditions']);

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
        if (!isset(self::$allowedTypes[$type])) {
            return $result;
        }

        if (!$this->_allowShowDatas($type, $value)) {
            return $result;
        }

        $challenged = $this->_challengeFromTo($from, $to);
        if ($challenged===false) {
            return $result;
        }

        $newParams = [
            'type'=> $type,
            'type_value'=> $value,
            'conditions'=> []
        ];

        if ($challenged) {
            list($from, $to) = $challenged;
            $newParams['conditions']['from'] = $from;
            $newParams['conditions']['to'] = $to;
        }
        if (!empty($params['allowed_product_types']) && is_array($params['allowed_product_types'])) {
            $newParams['conditions']['product_types'] = $params['allowed_product_types'];
        }
        if (isset($params['q']) && trim($params['q'])!=='') {
            $qCondition = $this->getQCondition(trim($params['q']), $type);
            if ($qCondition) {
                $newParams['conditions']['q'] = $qCondition;
            }
        }

        $token = md5(J($params));
        $params = $newParams;

        $db = \Gini\DataBase::db();
        $tableName = self::_getOPTableName();

        $this->select('COUNT(*)', $tableName) 
            ->where(self::$allowedTypes[$type], '=', $value);
        if (isset($params['conditions']['from']) && isset($params['conditions']['to'])) {
            $this->where('order_mtime', 'between', $params['conditions']['from'], $params['conditions']['to']);
        }
        if (isset($params['conditions']['product_types'])) {
            $this->where('product_type', 'in', $params['conditions']['product_types']);
        }
        if (isset($params['conditions']['q'])) {
            $this->where($params['conditions']['q']);
        }

        $sql = $this->groupBy('cas_no')->getSQL();
        $total = $db->query($sql)->count();

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

        $result = $this->_getProducts($type, $value, $params['conditions'], $start, $perpage);

        return $result;
    }

    private function _getProducts($col, $value, $conditions, $start = 0, $perpage = 5)
    {
        $tableName = self::_getOPTableName();
        $db = \Gini\DataBase::db();
        $this->select('id,cas_no,GROUP_CONCAT(DISTINCT product_type) AS ptype', $tableName)->where('cas_no', '!=', '')->where($col, '=', $value)->where('order_status', '!=', \Gini\ORM\Order::STATUS_CANCELED);
        if (isset($conditions['product_types'])) {
            $this->where('product_type', 'in', $conditions['product_types']);
        }
        if (isset($conditions['from']) && isset($conditions['to'])) {
            $this->where('order_mtime', 'between', $conditions['from'], $conditions['to']);
        }
        if (isset($conditions['q'])) {
            $this->where($conditions['q']);
        }
        $sql = $this->groupBy('cas_no')->orderBy([['product_type', 'desc']])->limit($start, $perpage)->getSQL();
        $query = $db->query($sql);
        $rows = $query ? $query->rows() : [];

        $result = [];
        foreach ($rows as $row) {
            $tmpStart = 0;
            $tmpPerpage = 100;
            $tmpName = '';
            $tmpCount = 0;
            $tmpPrices = 0;
            while (true) {
                $this->select('product_name,product_package,product_quantity,product_total_price', $tableName)
                    ->where($col, '=', $value)
                    ->where('cas_no', '=', $row->cas_no)
                    ->where('order_status', '!=', \Gini\ORM\Order::STATUS_CANCELED);
                if (isset($conditions['product_types'])) {
                    $this->where('product_type', 'in', $conditions['product_types']);
                }
                if (isset($conditions['from']) && isset($conditions['to'])) {
                    $this->where('order_mtime', 'between', $conditions['from'], $conditions['to']);
                }
                if (isset($conditions['q'])) {
                    $this->where($conditions['q']);
                }
                $this->groupBy(['order_id', 'product_id']);
                $sql = $this->limit($tmpStart, $tmpPerpage)->getSQL();
                $query = $db->query($sql);
                $tmpRows = $query ? $query->rows() : [];
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
                'quantity' => $tmpCount,
                'price' => round($tmpPrices, 2),
                'type' => $row->ptype
            ];
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
        if ($from>=$max) return false;
        $from = ($from && $from>=$min && $from<=$max) ? $from : $min;
        $to = ($to && $to>=$min && $to<=$max) ? $to : $max;
        if ($from==$min && $to==$max) return;
        return [
            date('Y-m-d H:i:s', min($from, $to)),
            date('Y-m-d 23:59:59', max($from, $to))
        ];
    }

    // 求有效总数信息
    private function _getTotalInfo($type, $conditions, $col=null, $value=null)
    {
        $tableName = self::_getOPTableName();
        $this->select('order_id,order_price', $tableName);
        switch ($type) {
        case 'valid':
            $this->where('order_status', '!=', \Gini\ORM\Order::STATUS_CANCELED);
            break;
        case 'transferred':
            $this->where('order_status', '=', \Gini\ORM\Order::STATUS_TRANSFERRED);
            break;
        case 'paid':
            $this->where('order_status', '=', \Gini\ORM\Order::STATUS_PAID);
            break;
        case 'canceled':
            $this->where('order_status', '=', \Gini\ORM\Order::STATUS_CANCELED);
            break;
        }
        if (isset($conditions['product_types'])) {
            $this->where('product_type', 'in', $conditions['product_types']);
        }
        if (isset($conditions['from']) && isset($conditions['to'])) {
            $this->where('order_mtime', 'between', $conditions['from'], $conditions['to']);
        }
        if (isset($conditions['q'])) {
            $this->where($conditions['q']);
        }
        if (!is_null($col)) {
            $this->where($col, '=', $value);
        }
        $sql = $this->groupBy('order_id')->getSQL();
        $sql = "SELECT COUNT(order_id) AS ct, SUM(order_price) AS op FROM ({$sql}) T1";
        $db = \Gini\Database::db();
        $row = $db->query($sql)->row();
        return [$row->ct?:0, $row->op?:0];
    }

    private $currentSQL = '';
    private $currentSQLHasWhere = false;
    private function select($cols, $tableName)
    {
        $db = \Gini\Database::db();
        $sql = "SELECT {$cols} FROM " . $db->quoteIdent($tableName);
        $this->currentSQL = $sql;
        $this->currentSQLHasWhere = false;
        return $this;
    }

    private function getSubWhere()
    {
        $db = \Gini\Database::db();
        $args = func_get_args();
        $result = '';
        switch (count($args)) {
        case 3: // =,!=,>=,like...
            if (strtoupper($args[1])=='IN') {
                if (is_array($args[2])) {
                    $ins = [];
                    foreach ($args[2] as $in) {
                        $ins[] = $db->quote($in);
                    }
                    $result = strtr(":col IN :val", [
                        ':col'=> $db->quoteIdent($args[0]),
                        ':val'=> '(' . implode(',', $ins) . ')'
                    ]);
                }
            }
            else {
                $result = strtr(":col :op :val", [
                    ':col'=> $db->quoteIdent($args[0]),
                    ':op'=> $args[1],
                    ':val'=> $db->quote($args[2])
                ]);
            }
            break;
        case 4: // between ... and
            $result = strtr(":col BETWEEN :from AND :to", [
                ':col'=> $db->quoteIdent($args[0]),
                ':from'=> $db->quote($args[2]),
                ':to'=> $db->quote($args[3])
            ]);
            break;
        }
        return $result;
    }

    //where([[$col, $op, $val],..]);
    //where($col, $op, $val);
    private function where()
    {
        $args = func_get_args();
        $prefix = $this->currentSQLHasWhere ? 'AND' : 'WHERE';
        switch (count($args)) {
        case 1: // need array
            if (is_array($args[0])) {
                $subs = [];
                foreach ($args[0] as $ags) {
                    $subs[] = call_user_func_array([$this, 'getSubWhere'], $ags);
                }
                $subSQL = '(' . implode(' OR ', $subs) . ')';
            }
            break;
        case 3: // =,!=,>=,like...
        case 4: // between ... and
            $this->currentSQLHasWhere = true;
            $subSQL = call_user_func_array([$this, 'getSubWhere'], $args);
            break;
        }
        if ($subSQL) {
            $this->currentSQL .= " {$prefix} {$subSQL}";
        }
        return $this;
    }

    private function groupBy($cons)
    {
        $by = $cons;
        if (is_array($cons)) {
            $by = implode(',', $cons);
        }
        $this->currentSQL .= " GROUP BY {$by}";
        return $this;
    }

    private function orderBy(array $cons)
    {
        $orders = [];
        $defaultSort = 'asc';
        $db = \Gini\DataBase::db();
        foreach ($cons as $con) {
            if (is_array($con)) {
                $col = $con[0];
                $sort = $con[1] ?: $defaultSort;
            }
            else {
                $col = $con;
                $sort = $defaultSort;
            }
            $orders[] = strtr(":col :sort", [
                ':col'=> $db->quoteIdent($col),
                ':sort'=> $sort
            ]);
        }
        if (!empty($orders)) {
            $this->currentSQL .= " ORDER BY " . implode(',', $orders);
        }
        return $this;
    }

    private function limit($start, $perpage)
    {
        $this->currentSQL .= " LIMIT {$start},{$perpage}";
        return $this;
    }

    private function getSQL()
    {
        return $this->currentSQL;
    }

    private function getQCondition($q, $type=null)
    {
        $q = trim($q);
        if ($q==='') return;
        $types = [
            'group'=> [
                'product_name',
                'group_name',
            ],
            'vendor'=> [
                'product_name',
                'vendor_name'
            ],
            'type'=> [
                'product_name',
                'group_name',
                'vendor_name'
            ],
        ];
        if (!in_array($type, array_keys($types))) {
            return;
        }
        $pattern = '/(?:\d{2,7})-(?:\d{2})-(?:\d)/';
        if (preg_match($pattern, $q, $matches)) {
            $casNO = $matches[0];
            $q = str_replace($casNO, '', $q);
            $q = str_replace('/\s+/', ' ', $q);
        }
        $conditions = [];
        if ($q!=='') {
            $q = "%{$q}%";
            foreach ($types[$type] as $key) {
                $conditions[] = [$key, 'like', $q];
            }
        }
        if ($casNO) {
            $conditions[] = ['cas_no', '=', $casNO];
        }

        return $conditions;
    }
}

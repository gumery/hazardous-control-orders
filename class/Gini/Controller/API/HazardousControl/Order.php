<?php


namespace Gini\Controller\API\HazardousControl;

class Order extends \Gini\Controller\API\HazardousControl\Base
{
    private static $allowedTypes = [
        'vendor' => 'vendor_id',
        'group' => 'group_id',
        'college' => 'college_code',
    ];

    private static function _getOPTableName()
    {
        return \Gini\Config::get('hazardous-control-orders.table') ?: '_hazardous_control_order_product';
    }

    public function actionSearchOrderStat($criteria)
    {
        $result = [
            'total' => 0,
            'token' => '',
        ];
        $type = $criteria['type'];
        if (!isset(self::$allowedTypes[$type])) {
            return $result;
        }

        $select           = $this->_getSelect($type);
        $where            = $this->_getWhere($criteria);
        $groupBy          = $this->_getGroupBy($type);
        $sql              = $select.$where.$groupBy;
        $db               = \Gini\Database::db();
        $query            = $db->query($sql);
        $count            = $query ? $query->count() : 0;
        $token            = md5(J($criteria));
        $_SESSION[$token] = $criteria;
        $result           = [
            'total' => $total,
            'token' => $token,
        ];
        return $result;
    }

    public function actionGetOrderStat($token, $start = 0, $perpage = 25)
    {
        $result   = [];
        $criteria = $_SESSION[$token];
        if (empty($criteria)) {
            return $result;
        }

        $start   = is_numeric($start) ? $start : 0;
        $perpage = min($perpage, 25);
        $dataSQL = $this->_getDataSQL($criteria, $start, $perpage);
        $statSQL = $this->_getStatSQL($criteria);
        $db      = \Gini\Database::db();
        $statQuery = $db->query($statSQL);
        $stat    = $statQuery ? $statQuery->row() : [];

        $dataQuery = $db->query($dataSQL);
        $results = $dataQuery ? $dataQuery->rows() : [];
        $count   = $dataQuery ? $dataQuery->count() : 0;
        $data = [];
        $data['stat'] = [
            'count' => [
                'pending'     => $stat->pending_count,
                'transferred' => $stat->transferred_count,
                'paid'        => $stat->paid_count,
                'canceled'    => $stat->canceled_count,
                'total'       => $stat->total_count
                ],
            'balance' => [
                'pending'     => $stat->pending_balance,
                'transferred' => $stat->transferred_balance,
                'paid'        => $stat->paid_balance,
                'canceled'    => $stat->canceled_balance,
                'total'       => $stat->total_balance
            ],
            'product_count' => $stat->product_count,
        ];
        $type = $criteria['type'];
        $data['stat'][$type.'_count'] = $count;

        $info = [];
        foreach ($results as $result) {
            $name_col = $type.'_name';
            $info[] = [
                $name_col       => $result->$name_col,
                'product_count' => $result->product_count,
                'balance'       => [
                    'pending'     => $result->pending_balance,
                    'transferred' => $result->transferred_balance,
                    'paid'        => $result->paid_balance,
                    'canceled'    => $result->canceled_balance,
                    'total'       => $result->total_balance,
                ],
                'count' => [
                    'pending'     => $result->pending_count,
                    'transferred' => $result->transferred_count,
                    'paid'        => $result->paid_count,
                    'canceled'    => $result->canceled_count,
                    'total'       => $result->total_count,
                ],
            ];
        }
        $data['data'] = $info;
        return $data;
    }

    private function _getStatSQL($criteria)
    {
        $type    = $criteria['type'];
        $select  = $this->_getSelect($type);
        $where   = $this->_getWhere($criteria);
        return $select.$where;
    }


    private function _getDataSQL($criteria, $start = 0, $perpage = 20)
    {
        $limit   = " LIMIT {$start}, {$perpage}";
        $type    = $criteria['type'];
        $select  = $this->_getSelect($type);
        $where   = $this->_getWhere($criteria);
        $groupBy = $this->_getGroupBy($type);
        return $select.$where.$groupBy.$limit;
    }

    private function _getGroupBy($type)
    {
        $col = self::$allowedTypes[$type];
        $groupBy = " GROUP BY {$col}";
        return $groupBy;
    }

    private function _getWhere($criteria)
    {
        $where  = [];
        $sql    = '';
        $db     = \Gini\Database::db();
        if (isset($criteria['college_name'])) {
            $college_name = $db->quote('%'.$criteria['college_name'].'%');
            $where[] = "`college_name` LIKE {$college_name}";
        }
        if (isset($criteria['group_name'])) {
            $group_name = $db->quote('%'.$criteria['group_name'].'%');
            $where[] = "`group_name` LIKE {$group_name}";
        }
        if (isset($criteria['vendor_name'])) {
            $vendor_name = $db->quote('%'.$criteria['vendor_name'].'%');
            $where[] = "`vendor_name` LIKE {$vendor_name}";
        }
        if (isset($criteria['dtstart'])) {
            $dtstart = $db->quote($criteria['dtstart']);
            $where[] = "`order_ctime` > {$dtstart}";
        }
        if (isset($criteria['dtend'])) {
            $dtend = $db->quote($criteria['dtend']);
            $where[] = "`order_ctime` < {$dtend}";
        }
        if (isset($criteria['product_type'])) {
            $chem_types = [
                'chem_reagent',
                'bio_reagent',
                'consumable',
                'normal',
                'hazardous',
                'drug_precursor',
                'highly_toxic',
                'explosive',
                'psychotropic',
                'narcotic',
            ];
            if (in_array($criteria['product_type'], $chem_types)) {
                $product_type = $criteria['product_type'];
                $type_value = $db->quote($product_type);
                $where[] = "`$product_type` = 1";
            }
        }
        if (count($where)) {
            $sql = ' WHERE '.implode(' AND ', $where);
        }
        return $sql;
    }

    private function _getSelect($type)
    {
        $db = \Gini\Database::db();
        $tableName = self::_getOPTableName();
        $sql = 'SELECT ';

        switch ($type) {
            case 'group':
                $subSql = 'group_name,';
                break;
            case 'college':
                $subSql = 'college_name,';
                break;
            case 'vendor':
            default:
                $subSql = 'vendor_name,';
                break;
        }
        if ($subSql) {
            $sql .= $subSql;
        }
        // 待付款金额总计
        $pending_status     = \Gini\ORM\Order::STATUS_APPROVED;
        $transferred_status = \Gini\ORM\Order::STATUS_TRANSFERRED;
        $paid_status        = \Gini\ORM\Order::STATUS_PAID;
        $canceled_status    = \Gini\ORM\Order::STATUS_CANCELED;
        $sql               .= "SUM( CASE WHEN order_status={$pending_status} THEN product_total_price ELSE 0 END) AS pending_balance,";
        $sql               .= "SUM( CASE WHEN order_status={$transferred_status} THEN product_total_price ELSE 0 END) AS transferred_balance,";
        $sql               .= "SUM( CASE WHEN order_status={$paid_status} THEN product_total_price ELSE 0 END) AS paid_balance,";
        $sql               .= "SUM( CASE WHEN order_status={$canceled_status} THEN product_total_price ELSE 0 END) AS canceled_balance,";
        $sql               .= "SUM(product_total_price) AS total_balance,";

        $sql               .= "COUNT(DISTINCT CASE WHEN order_status={$pending_status} THEN order_id END) AS pending_count,";
        $sql               .= "COUNT(DISTINCT CASE WHEN order_status={$transferred_status} THEN order_id END) AS transferred_count,";
        $sql               .= "COUNT(DISTINCT CASE WHEN order_status={$paid_status} THEN order_id END) AS paid_count,";
        $sql               .= "COUNT(DISTINCT CASE WHEN order_status={$canceled_status} THEN order_id END) AS canceled_count,";
        $sql               .= "COUNT(DISTINCT order_id) AS total_count,";
        $sql .= "SUM(product_quantity) as product_count";

        $sql .= ' FROM '.$db->quoteIdent($tableName);
        return $sql;
    }

}

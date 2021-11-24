<?php

    namespace YTT;

    require_once __DIR__ . "/DBConnection.class.php";
    require_once __DIR__ . "/RouteHandler.class.php";
    require_once __DIR__ . "/UsersHandler.class.php";

    class StatsHandler extends RouteHandler
    {
        private $DEFAULT_RANGE = 31;
        private $MAX_RANGE_DAYS = 3650;

        public function __construct(){ }

        private function getDataType($name)
        {
            switch($name)
            {
                case "2":
                case "opened":
                    return 2;
                case "1":
                case "watched":
                    return 1;
            }
            return $name;
        }

        private function getUserDurationStats($userUUID, $category, $range = null)
        {
            if(!is_int($range))
            {
                $range = $this->DEFAULT_RANGE;
            }

            $range = min($range, $this->MAX_RANGE_DAYS);

            $data = array();
            $prepared = $this->getConnection()->prepare("SELECT SUM(`YTT_Records`.`Stat`) AS `Stat`, `StatDay` AS `StatDay` FROM `YTT_Records` LEFT JOIN `YTT_Users` ON `YTT_Records`.`UserId` = `YTT_Users`.`ID` WHERE YTT_Users.UUID = :uuid AND `YTT_Records`.`Type` = :type AND `StatDay` >= DATE_SUB(NOW(), INTERVAL :days DAY) AND `StatDay` <= DATE_ADD(NOW(), INTERVAL 1 DAY) GROUP BY `StatDay`");
            $prepared->execute(array(":uuid" => $userUUID, ':days' => $range, ':type' => $this->getDataType($category)));
            $result = $prepared->fetchAll();
            foreach($result as $key => $row)
            {
                $data[] = array('date' => $row['StatDay'], 'value' => $row['Stat'], 'duration' => $row['Stat']);
            }
            return $data;
        }

        /** @noinspection PhpUnused */
        public function getUserWatched($groups, $params)
        {
            return $this->getUserDurationStats($groups[1], "watched", isset($params["range"]) ? $params['range'] : null);
        }

        /** @noinspection PhpUnused */
        public function getUserOpened($groups, $params)
        {
            return $this->getUserDurationStats($groups[1], "opened", isset($params["range"]) ? $params['range'] : null);
        }

        /** @noinspection PhpUnused */
        public function getUserOpenedCount($groups, $params)
        {
            $userUUID = $groups[1];
            $range = min(3650, isset($params["range"]) ? $params['range'] : $this->DEFAULT_RANGE);

            $userHandler = new UsersHandler();
            $userId = $userHandler->getUserId($userUUID);

            $data = array();
            $prepared = $this->getConnection()->prepare("SELECT SUM(`Amount`) AS Total, StatDay AS `StatDay` FROM `YTT_Records` WHERE `Type`=:type AND `UserId`=:userId AND StatDay >= DATE_SUB(NOW(), INTERVAL :days DAY) AND StatDay <= DATE_ADD(NOW(), INTERVAL 1 DAY) GROUP BY `StatDay`");
            $prepared->execute(array(":userId" => $userId, ':days' => $range, ':type' => $this->getDataType("opened")));
            $result = $prepared->fetchAll();
            foreach($result as $key => $row)
            {
                $data[] = array('date' => $row['StatDay'], 'value' => $row['Total']);
            }
            return $data;
        }

        private function getUserSumStats($userId, $type, $days)
        {
            $prepared = $this->getConnection()->prepare("SELECT SUM(`Stat`)/1000 AS Total FROM `YTT_Records` WHERE `Type`=:type AND `UserId`=:userId AND StatDay >= DATE_SUB(NOW(), INTERVAL :days DAY ) AND StatDay <= DATE_ADD(NOW(), INTERVAL 1 DAY)");
            $prepared->execute(array(":userId" => $userId, ':days' => $days, ':type' => $this->getDataType($type)));
            if($row = $prepared->fetch())
            {
                return doubleval($row['Total']);
            }
            return 0;
        }

        private function getUserCountStats($userId, $days)
        {
            $prepared = $this->getConnection()->prepare("SELECT SUM(`Amount`) AS Total FROM `YTT_Records` WHERE `Type`=:type AND `UserId`=:userId AND StatDay >= DATE_SUB(NOW(), INTERVAL :days DAY ) AND StatDay <= DATE_ADD(NOW(), INTERVAL 1 DAY) AND Stat > 0");
            $prepared->execute(array(":userId" => $userId, ':days' => $days, ':type' => $this->getDataType('opened')));
            if($row = $prepared->fetch())
            {
                return doubleval($row['Total']);
            }
            return 0;
        }

        /** @noinspection PhpUnused */
        public function getUserStats($groups, $params)
        {
            $userUUID = $groups[1];

            $userHandler = new UsersHandler();
            $userId = $userHandler->getUserId($userUUID);

            return array('total' => array('opened' => $this->getUserSumStats($userId, 'opened', $this->MAX_RANGE_DAYS), 'watched' => $this->getUserSumStats($userId, 'watched', $this->MAX_RANGE_DAYS), 'count' => $this->getUserCountStats($userId, $this->MAX_RANGE_DAYS)), 'week' => array('opened' => $this->getUserSumStats($userId, 'opened', 7), 'watched' => $this->getUserSumStats($userId, 'watched', 7), 'count' => $this->getUserCountStats($userId, 7)), 'day' => array('opened' => $this->getUserSumStats($userId, 'opened', 1), 'watched' => $this->getUserSumStats($userId, 'watched', 1), 'count' => $this->getUserCountStats($userId, 1)));
        }

        /**
         * @param array $groups 1: UUID
         * @param array $params type, stat, date
         * @return array
         */
        public function addUserStat($groups, $params)
        {
            $userUUID = $groups[1];

            if(!StatsHandler::checkFields($params, ['type', 'stat', 'date']))
            {
                return array('code' => 400, 'message' => 'Missing fields');
            }

            $userHandler = new UsersHandler();
            $userId = $userHandler->getUserIdOrCreate($userUUID);

            $query = $this->getConnection()->prepare("INSERT INTO `YTT_Records`(`UserId`, `Type`, `Stat`, `StatDay`) VALUES(:userId, :type, :stat, STR_TO_DATE(:timee, '%Y-%m-%d')) ON DUPLICATE KEY UPDATE `Stat`=`Stat`+VALUES(`Stat`);");
            if(!$query->execute(array(':userId' => $userId, ':type' => $this->getDataType($params['type']), ':stat' => $params['stat'], ':timee' => $this->getTimestamp($params['date']))))
            {
                return array('code' => 400, 'result' => 'err', 'error' => 'E2.2');
            }
            $query = $this->getConnection()->prepare("UPDATE `YTT_Users` SET `LastActivity`=CURRENT_TIMESTAMP() WHERE ID=:userId");
            $query->execute(array(':userId' => $userId));
            return array('code' => 200, 'result' => 'OK');
        }

        /**
         * @param int $date
         * @return string
         */
        private function getTimestamp($date)
        {
            if(!$date)
            {
                return date('%Y-%m-%d');
            }
            return $date;
        }
    }
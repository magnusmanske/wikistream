# ************************************************************
# Sequel Ace SQL dump
# Version 20096
#
# https://sequel-ace.com/
# https://github.com/Sequel-Ace/Sequel-Ace
#
# Host: tools-db (MySQL 5.5.5-10.6.22-MariaDB-log)
# Database: s55714__wikiflix_p
# Generation Time: 2026-05-14 09:13:24 +0000
# ************************************************************


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
SET NAMES utf8mb4;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE='NO_AUTO_VALUE_ON_ZERO', SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


# Dump of table blacklist
# ------------------------------------------------------------

CREATE TABLE `blacklist` (
  `q` int(11) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`q`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table file
# ------------------------------------------------------------

CREATE TABLE `file` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `item_q` int(11) unsigned NOT NULL,
  `property` int(11) unsigned NOT NULL,
  `key` varchar(255) NOT NULL,
  `is_trailer` tinyint(1) NOT NULL DEFAULT 0,
  `minutes` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `movie_q` (`item_q`),
  CONSTRAINT `file_ibfk_1` FOREIGN KEY (`item_q`) REFERENCES `item` (`q`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table group
# ------------------------------------------------------------

CREATE TABLE `group` (
  `q` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `type_q` int(10) unsigned DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `year` int(11) DEFAULT NULL,
  `ts` varchar(14) NOT NULL,
  PRIMARY KEY (`q`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table group_item
# ------------------------------------------------------------

CREATE TABLE `group_item` (
  `group_q` int(10) unsigned NOT NULL,
  `item_q` int(10) unsigned NOT NULL,
  `position` decimal(8,2) DEFAULT NULL,
  `subgroup` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`group_q`,`item_q`),
  KEY `item_q` (`item_q`),
  CONSTRAINT `group_item_ibfk_1` FOREIGN KEY (`item_q`) REFERENCES `item` (`q`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table item
# ------------------------------------------------------------

CREATE TABLE `item` (
  `q` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `available` tinyint(1) NOT NULL DEFAULT 0,
  `year` int(11) DEFAULT NULL,
  `minutes` int(11) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `sites` int(11) NOT NULL DEFAULT 0,
  `ts` varchar(14) NOT NULL,
  `ts_added` datetime NOT NULL DEFAULT current_timestamp(),
  `primary_type_q` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`q`),
  KEY `available` (`available`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table item_no_files
# ------------------------------------------------------------

CREATE TABLE `item_no_files` (
  `q` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `year` int(11) DEFAULT NULL,
  `minutes` int(11) DEFAULT NULL,
  `sites` int(11) NOT NULL DEFAULT 0,
  `ia_results` int(11) DEFAULT NULL,
  `commons_results` int(11) DEFAULT NULL,
  PRIMARY KEY (`q`),
  KEY `sites` (`sites`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table kv
# ------------------------------------------------------------

CREATE TABLE `kv` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(64) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table label
# ------------------------------------------------------------

CREATE TABLE `label` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `q` int(11) unsigned NOT NULL,
  `language` varchar(8) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `q` (`q`,`language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table logging
# ------------------------------------------------------------

CREATE TABLE `logging` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` varchar(10) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `event` varchar(16) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `q` int(11) DEFAULT NULL,
  `counter` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `timestamp` (`timestamp`,`event`,`q`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table person
# ------------------------------------------------------------

CREATE TABLE `person` (
  `q` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(255) NOT NULL,
  `gender` enum('M','F','?') NOT NULL DEFAULT '?',
  `sites` int(11) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`q`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table section
# ------------------------------------------------------------

CREATE TABLE `section` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `item_q` int(11) unsigned NOT NULL,
  `property` int(11) unsigned NOT NULL,
  `section_q` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `audio_q` (`item_q`,`property`,`section_q`),
  KEY `section` (`section_q`),
  KEY `property_section` (`property`,`section_q`),
  CONSTRAINT `section_ibfk_1` FOREIGN KEY (`item_q`) REFERENCES `item` (`q`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table user
# ------------------------------------------------------------

CREATE TABLE `user` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table user_item_list
# ------------------------------------------------------------

CREATE TABLE `user_item_list` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `q` int(11) NOT NULL,
  `added` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`,`q`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





























# Dump of view vw_most_popular_person
# ------------------------------------------------------------

CREATE ALGORITHM=UNDEFINED DEFINER=`s55714`@`%` SQL SECURITY DEFINER VIEW `vw_most_popular_person`
AS SELECT
   `logging`.`q` AS `q`,sum(`logging`.`counter`) AS `cnt`
FROM `logging` where `logging`.`event` = 'person_loaded' and `logging`.`q` is not null group by `logging`.`q` order by sum(`logging`.`counter`) desc;

# Dump of view vw_popular_entries
# ------------------------------------------------------------

CREATE ALGORITHM=UNDEFINED DEFINER=`s55714`@`%` SQL SECURITY DEFINER VIEW `vw_popular_entries`
AS SELECT
   `vw_ranked_entries_blacklist`.`q` AS `q`,
   `vw_ranked_entries_blacklist`.`title` AS `title`,
   `vw_ranked_entries_blacklist`.`available` AS `available`,
   `vw_ranked_entries_blacklist`.`year` AS `year`,
   `vw_ranked_entries_blacklist`.`minutes` AS `minutes`,
   `vw_ranked_entries_blacklist`.`image` AS `image`,
   `vw_ranked_entries_blacklist`.`sites` AS `sites`,
   `vw_ranked_entries_blacklist`.`ts` AS `ts`,
   `vw_ranked_entries_blacklist`.`files` AS `files`,(select count(0)
FROM `section` where `section`.`property` = 136 and `section`.`section_q` = 226730 and `section`.`item_q` = `vw_most_popular_items_played`.`q`) AS `is_silent` from (`vw_most_popular_items_played` join `vw_ranked_entries_blacklist`) where `vw_most_popular_items_played`.`q` = `vw_ranked_entries_blacklist`.`q` order by `vw_most_popular_items_played`.`cnt` desc,`vw_most_popular_items_played`.`q`;

# Dump of view vw_section_property_q
# ------------------------------------------------------------

CREATE ALGORITHM=UNDEFINED DEFINER=`s55714`@`%` SQL SECURITY DEFINER VIEW `vw_section_property_q`
AS SELECT
   `section`.`property` AS `property`,
   `section`.`section_q` AS `section_q`,count(`section`.`item_q`) AS `cnt`
FROM `section` group by `section`.`property`,`section`.`section_q` order by count(`section`.`item_q`) desc;

# Dump of view vw_user_item_list
# ------------------------------------------------------------

CREATE ALGORITHM=UNDEFINED DEFINER=`s55714`@`%` SQL SECURITY DEFINER VIEW `vw_user_item_list`
AS SELECT
   `user`.`name` AS `name`,
   `user_item_list`.`user_id` AS `user_id`,
   `user_item_list`.`q` AS `q`,
   `user_item_list`.`added` AS `added`
FROM (`user` join `user_item_list`) where `user`.`id` = `user_item_list`.`user_id`;

# Dump of view vw_ranked_entries_blacklist
# ------------------------------------------------------------

CREATE ALGORITHM=UNDEFINED DEFINER=`s55714`@`%` SQL SECURITY DEFINER VIEW `vw_ranked_entries_blacklist`
AS SELECT
   `vw_ranked_entries`.`q` AS `q`,
   `vw_ranked_entries`.`title` AS `title`,
   `vw_ranked_entries`.`available` AS `available`,
   `vw_ranked_entries`.`year` AS `year`,
   `vw_ranked_entries`.`minutes` AS `minutes`,
   `vw_ranked_entries`.`image` AS `image`,
   `vw_ranked_entries`.`sites` AS `sites`,
   `vw_ranked_entries`.`ts` AS `ts`,
   `vw_ranked_entries`.`ts_added` AS `ts_added`,
   `vw_ranked_entries`.`files` AS `files`,(select count(0)
FROM `section` where `section`.`property` = 136 and `section`.`section_q` = 226730 and `section`.`item_q` = `vw_ranked_entries`.`q`) AS `is_silent` from `vw_ranked_entries` where !(`vw_ranked_entries`.`q` in (select `blacklist`.`q` from `blacklist`)) order by `vw_ranked_entries`.`sites` desc,`vw_ranked_entries`.`minutes` desc,`vw_ranked_entries`.`q`;

# Dump of view vw_most_popular_items_played
# ------------------------------------------------------------

CREATE ALGORITHM=UNDEFINED DEFINER=`s55714`@`%` SQL SECURITY DEFINER VIEW `vw_most_popular_items_played`
AS SELECT
   `logging`.`q` AS `q`,sum(`logging`.`counter`) AS `cnt`
FROM `logging` where `logging`.`event` = 'play_page_loaded' and `logging`.`q` is not null group by `logging`.`q` order by sum(`logging`.`counter`) desc;

# Dump of view vw_items_per_day
# ------------------------------------------------------------

CREATE ALGORITHM=UNDEFINED DEFINER=`s55714`@`%` SQL SECURITY DEFINER VIEW `vw_items_per_day`
AS SELECT
   substr(`logging`.`timestamp`,1,8) AS `day`,max(`logging`.`counter`) AS `cnt`
FROM `logging` where `logging`.`event` = 'total_items' group by substr(`logging`.`timestamp`,1,8) order by substr(`logging`.`timestamp`,1,8) desc;

# Dump of view vw_recently_added
# ------------------------------------------------------------

CREATE ALGORITHM=UNDEFINED DEFINER=`s55714`@`%` SQL SECURITY DEFINER VIEW `vw_recently_added`
AS SELECT
   `item`.`q` AS `q`,
   `item`.`title` AS `title`,
   `item`.`available` AS `available`,
   `item`.`year` AS `year`,
   `item`.`minutes` AS `minutes`,
   `item`.`image` AS `image`,
   `item`.`sites` AS `sites`,
   `item`.`ts` AS `ts`,
   `vw_file`.`j` AS `files`,(select count(0)
FROM `section` where `section`.`property` = 136 and `section`.`section_q` = 226730 and `section`.`item_q` = `item`.`q`) AS `is_silent` from (`item` join `vw_file`) where `vw_file`.`item_q` = `item`.`q` order by `item`.`ts` desc,`item`.`minutes` desc,`item`.`q`;

# Dump of view vw_ranked_entries
# ------------------------------------------------------------

CREATE ALGORITHM=UNDEFINED DEFINER=`s55714`@`%` SQL SECURITY DEFINER VIEW `vw_ranked_entries`
AS SELECT
   `item`.`q` AS `q`,
   `item`.`title` AS `title`,
   `item`.`available` AS `available`,
   `item`.`year` AS `year`,
   `item`.`minutes` AS `minutes`,
   `item`.`image` AS `image`,
   `item`.`sites` AS `sites`,
   `item`.`ts` AS `ts`,
   `item`.`ts_added` AS `ts_added`,
   `vw_file`.`j` AS `files`,(select count(0)
FROM `section` where `section`.`property` = 136 and `section`.`section_q` = 226730 and `section`.`item_q` = `item`.`q`) AS `is_silent` from (`item` join `vw_file`) where `vw_file`.`item_q` = `item`.`q` order by `item`.`sites` desc,`item`.`minutes` desc,`item`.`q`;

# Dump of view vw_file
# ------------------------------------------------------------

CREATE ALGORITHM=UNDEFINED DEFINER=`s55714`@`%` SQL SECURITY DEFINER VIEW `vw_file`
AS SELECT
   `file`.`item_q` AS `item_q`,concat('[',group_concat(json_object('property',`file`.`property`,'key',`file`.`key`,'is_trailer',`file`.`is_trailer`,'minutes',`file`.`minutes`) separator ','),']') AS `j`
FROM `file` group by `file`.`item_q`;

# Dump of view vw_most_popular_item
# ------------------------------------------------------------

CREATE ALGORITHM=UNDEFINED DEFINER=`s55714`@`%` SQL SECURITY DEFINER VIEW `vw_most_popular_item`
AS SELECT
   `logging`.`q` AS `q`,sum(`logging`.`counter`) AS `cnt`
FROM `logging` where `logging`.`event` = 'entry_loaded' and `logging`.`q` is not null group by `logging`.`q` order by sum(`logging`.`counter`) desc;

# Dump of view vw_item_to_played_ratio
# ------------------------------------------------------------

CREATE ALGORITHM=UNDEFINED DEFINER=`s55714`@`%` SQL SECURITY DEFINER VIEW `vw_item_to_played_ratio`
AS SELECT
   `played`.`q` AS `q`,
   `item`.`cnt` AS `item_cnt`,
   `played`.`cnt` AS `played_cnt`,
   `played`.`cnt` / `item`.`cnt` * 100 AS `percent`
FROM (`vw_most_popular_items_played` `played` join `vw_most_popular_item` `item`) where `item`.`q` = `played`.`q`;

# Dump of view vw_play_page_loaded_per_day
# ------------------------------------------------------------

CREATE ALGORITHM=UNDEFINED DEFINER=`s55714`@`%` SQL SECURITY DEFINER VIEW `vw_play_page_loaded_per_day`
AS SELECT
   concat(substr(`logging`.`timestamp`,1,4),'-',substr(`logging`.`timestamp`,5,2),'-',substr(`logging`.`timestamp`,7,2)) AS `day`,sum(`logging`.`counter`) AS `cnt`
FROM `logging` where `logging`.`event` = 'play_page_loaded' group by concat(substr(`logging`.`timestamp`,1,4),'-',substr(`logging`.`timestamp`,5,2),'-',substr(`logging`.`timestamp`,7,2)) order by concat(substr(`logging`.`timestamp`,1,4),'-',substr(`logging`.`timestamp`,5,2),'-',substr(`logging`.`timestamp`,7,2));


/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

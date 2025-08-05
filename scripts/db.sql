CREATE TABLE `item` (
  `q` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `available` tinyint(1) NOT NULL DEFAULT 0,
  `year` int(11) DEFAULT NULL,
  `minutes` int(11) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `sites` int(11) NOT NULL DEFAULT 0,
  `ts` varchar(14) NOT NULL,
  PRIMARY KEY (`q`),
  KEY `available` (`available`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kv` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(64) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `label` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `q` int(11) unsigned NOT NULL,
  `language` varchar(8) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `q` (`q`,`language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `file` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `item_q` int(11) unsigned NOT NULL,
  `property` int(11) unsigned NOT NULL,
  `key` varchar(255) NOT NULL,
  `is_trailer` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `movie_q` (`item_q`),
  CONSTRAINT `file_ibfk_1` FOREIGN KEY (`item_q`) REFERENCES `item` (`q`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `person` (
  `q` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(255) NOT NULL,
  `gender` enum('M','F','?') NOT NULL DEFAULT '?',
  `sites` int(11) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`q`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `section` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `item_q` int(11) unsigned NOT NULL,
  `property` int(11) unsigned NOT NULL,
  `section_q` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `audio_q` (`item_q`,`property`,`section_q`),
  KEY `section` (`section_q`),
  CONSTRAINT `section_ibfk_1` FOREIGN KEY (`item_q`) REFERENCES `item` (`q`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;




CREATE VIEW `vw_file` AS select `file`.`item_q` AS `item_q`,concat('[',group_concat(json_object('property',`file`.`property`,'key',`file`.`key`,'is_trailer',`file`.`is_trailer`) separator ','),']') AS `j` from `file` group by `file`.`item_q`;

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

CREATE VIEW `vw_section_property_q` AS select `section`.`property` AS `property`,`section`.`section_q` AS `section_q`,count(item_q) AS `cnt` from `section` group by `section`.`property`,`section`.`section_q` order by count(0) desc;

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


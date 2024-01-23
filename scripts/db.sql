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

CREATE VIEW `vw_ranked_entries` AS select `item`.`q` AS `q`,`item`.`title` AS `title`,`item`.`available` AS `available`,`item`.`year` AS `year`,`item`.`minutes` AS `minutes`,`item`.`image` AS `image`,`item`.`sites` AS `sites`,`item`.`ts` AS `ts`,`vw_file`.`j` AS `files` from (`item` join `vw_file`) where `vw_file`.`item_q` = `item`.`q` order by `item`.`sites` desc,`item`.`minutes` desc,`item`.`q`;

CREATE VIEW `vw_recently_added` AS select `item`.`q` AS `q`,`item`.`title` AS `title`,`item`.`available` AS `available`,`item`.`year` AS `year`,`item`.`minutes` AS `minutes`,`item`.`image` AS `image`,`item`.`sites` AS `sites`,`item`.`ts` AS `ts`,`vw_file`.`j` AS `files` from (`item` join `vw_file`) where `vw_file`.`item_q` = `item`.`q` order by `item`.`ts` desc,`item`.`minutes` desc,`item`.`q`;

CREATE VIEW `vw_section_property_q` AS select `section`.`property` AS `property`,`section`.`section_q` AS `section_q`,count(item_q) AS `cnt` from `section` group by `section`.`property`,`section`.`section_q` order by count(0) desc;

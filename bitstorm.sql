CREATE TABLE IF NOT EXISTS `peers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ipAddress` varchar(80) NOT NULL,
  `port` int(11) NOT NULL,
  `peerId` varchar(40) NOT NULL,
  `infoHash` varchar(40) NOT NULL,
  `key` varchar(40) NOT NULL,
  `userAgent` varchar(80) NOT NULL,
  `expire` int(11) NOT NULL,
  `isSeed` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `infoHash` (`infoHash`),
  KEY `peerId` (`peerId`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

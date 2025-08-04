-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jun 28, 2025 at 01:12 PM
-- Server version: 8.2.0
-- PHP Version: 8.2.13

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `inventory`
--

-- --------------------------------------------------------

--
-- Table structure for table `acreate`
--

DROP TABLE IF EXISTS `acreate`;
CREATE TABLE IF NOT EXISTS `acreate` (
  `id` int NOT NULL AUTO_INCREMENT,
  `aname` varchar(100) NOT NULL,
  `aemail` varchar(100) NOT NULL,
  `aphone` varchar(100) NOT NULL,
  `ausername` varchar(100) NOT NULL,
  `apassword` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `acreate`
--

INSERT INTO `acreate` (`id`, `aname`, `aemail`, `aphone`, `ausername`, `apassword`) VALUES
(2, 'Mehak Khan', 'mehak@gmail.com', '8011223344', 'ADMIN-801', 'PASS-0316');

-- --------------------------------------------------------

--
-- Table structure for table `add_dept`
--

DROP TABLE IF EXISTS `add_dept`;
CREATE TABLE IF NOT EXISTS `add_dept` (
  `id` int NOT NULL AUTO_INCREMENT,
  `did` varchar(100) NOT NULL,
  `aid` varchar(100) NOT NULL,
  `nqty` varchar(100) NOT NULL,
  `date` int NOT NULL,
  `month` int NOT NULL,
  `year` int NOT NULL,
  `status` varchar(123) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `add_lab`
--

DROP TABLE IF EXISTS `add_lab`;
CREATE TABLE IF NOT EXISTS `add_lab` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lid` int NOT NULL,
  `did` varchar(100) NOT NULL,
  `aid` varchar(100) NOT NULL,
  `aqty` varchar(100) NOT NULL,
  `date` int NOT NULL,
  `month` int NOT NULL,
  `year` int NOT NULL,
  `status` varchar(70) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `add_office`
--

DROP TABLE IF EXISTS `add_office`;
CREATE TABLE IF NOT EXISTS `add_office` (
  `id` int NOT NULL AUTO_INCREMENT,
  `oid` varchar(10) NOT NULL,
  `did` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `aid` varchar(100) NOT NULL,
  `aqty` varchar(100) NOT NULL,
  `date` varchar(21) NOT NULL,
  `month` varchar(32) NOT NULL,
  `year` varchar(21) NOT NULL,
  `status` varchar(23) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `add_public`
--

DROP TABLE IF EXISTS `add_public`;
CREATE TABLE IF NOT EXISTS `add_public` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pa_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `aid` varchar(100) NOT NULL,
  `aqty` varchar(100) NOT NULL,
  `date` int NOT NULL,
  `month` int NOT NULL,
  `year` int NOT NULL,
  `status` varchar(54) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `asset`
--

DROP TABLE IF EXISTS `asset`;
CREATE TABLE IF NOT EXISTS `asset` (
  `aid` int NOT NULL AUTO_INCREMENT,
  `aname` varchar(100) NOT NULL,
  `atype` varchar(100) NOT NULL,
  `aqty` int NOT NULL,
  PRIMARY KEY (`aid`)
) ENGINE=MyISAM AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assets_entry`
--

DROP TABLE IF EXISTS `assets_entry`;
CREATE TABLE IF NOT EXISTS `assets_entry` (
  `id` int NOT NULL AUTO_INCREMENT,
  `aid` int NOT NULL,
  `aname` varchar(100) NOT NULL,
  `atype` varchar(100) NOT NULL,
  `aqty` varchar(100) NOT NULL,
  `dop` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=111 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `classroom`
--

DROP TABLE IF EXISTS `classroom`;
CREATE TABLE IF NOT EXISTS `classroom` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cl_id` int NOT NULL,
  `did` int NOT NULL,
  `aid` varchar(100) NOT NULL,
  `aqty` varchar(100) NOT NULL,
  `date` varchar(2) NOT NULL,
  `month` varchar(33) NOT NULL,
  `year` varchar(32) NOT NULL,
  `status` varchar(12) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=348 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `complain`
--

DROP TABLE IF EXISTS `complain`;
CREATE TABLE IF NOT EXISTS `complain` (
  `co_id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `locationtype` varchar(123) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `did` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `pa_id` varchar(34) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `roomnum` int NOT NULL,
  `aid` varchar(22) NOT NULL,
  `descrip` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `date` int NOT NULL,
  `month` int NOT NULL,
  `year` int NOT NULL,
  `status` varchar(213) NOT NULL,
  PRIMARY KEY (`co_id`)
) ENGINE=MyISAM AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `course`
--

DROP TABLE IF EXISTS `course`;
CREATE TABLE IF NOT EXISTS `course` (
  `course_id` int NOT NULL AUTO_INCREMENT,
  `course_name` varchar(2345) NOT NULL,
  PRIMARY KEY (`course_id`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `course`
--

INSERT INTO `course` (`course_id`, `course_name`) VALUES
(5, 'BCA'),
(4, 'MA'),
(3, 'BA'),
(2, 'MSc'),
(1, 'BSc');

-- --------------------------------------------------------

--
-- Table structure for table `dept`
--

DROP TABLE IF EXISTS `dept`;
CREATE TABLE IF NOT EXISTS `dept` (
  `did` int NOT NULL AUTO_INCREMENT,
  `dname` varchar(100) NOT NULL,
  PRIMARY KEY (`did`)
) ENGINE=MyISAM AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `dept`
--

INSERT INTO `dept` (`did`, `dname`) VALUES
(1, 'Anthropology'),
(2, 'Assamese'),
(3, 'Bengali'),
(4, 'Economics'),
(5, 'Education'),
(6, 'English'),
(7, 'Geography'),
(8, 'History'),
(9, 'Hindi'),
(10, 'Mathematics'),
(11, 'Philosophy'),
(12, 'Political Science'),
(13, 'Psychology'),
(14, 'Sanskrit'),
(15, 'Sociology'),
(16, 'Statistics'),
(17, 'Botany'),
(18, 'Chemistry'),
(19, 'Computer Science'),
(20, 'Physics'),
(21, 'Zoology'),
(22, 'Administrative block'),
(23, 'Common');

-- --------------------------------------------------------

--
-- Table structure for table `lab`
--

DROP TABLE IF EXISTS `lab`;
CREATE TABLE IF NOT EXISTS `lab` (
  `lid` int NOT NULL AUTO_INCREMENT,
  `lname` varchar(43) NOT NULL,
  `did` varchar(56) NOT NULL,
  PRIMARY KEY (`lid`)
) ENGINE=MyISAM AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `lab`
--

INSERT INTO `lab` (`lid`, `lname`, `did`) VALUES
(1, 'Anthropology Lab', '1'),
(2, 'BioTech Lab', '17'),
(3, 'Chemistry Lab', '18'),
(4, 'Computer Lab', '19'),
(5, 'Zoology Lab', '21'),
(6, 'Physics Lab', '20');

-- --------------------------------------------------------

--
-- Table structure for table `off`
--

DROP TABLE IF EXISTS `off`;
CREATE TABLE IF NOT EXISTS `off` (
  `oid` int NOT NULL AUTO_INCREMENT,
  `oname` varchar(100) NOT NULL,
  PRIMARY KEY (`oid`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `off`
--

INSERT INTO `off` (`oid`, `oname`) VALUES
(1, 'Admin Office'),
(2, 'Faculty Office'),
(3, 'Common Office');

-- --------------------------------------------------------

--
-- Table structure for table `public`
--

DROP TABLE IF EXISTS `public`;
CREATE TABLE IF NOT EXISTS `public` (
  `pa_id` int NOT NULL AUTO_INCREMENT,
  `pa_name` varchar(23) NOT NULL,
  PRIMARY KEY (`pa_id`)
) ENGINE=MyISAM AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `public`
--

INSERT INTO `public` (`pa_id`, `pa_name`) VALUES
(1, 'Auditorium'),
(2, 'Conference Hall'),
(3, 'Library'),
(4, 'Boys\' Common Room'),
(5, 'Girls\' Common Room'),
(6, 'Boys\' Hostel'),
(7, 'Girls\' Hostel');

-- --------------------------------------------------------

--
-- Table structure for table `special_request`
--

DROP TABLE IF EXISTS `special_request`;
CREATE TABLE IF NOT EXISTS `special_request` (
  `sr_id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `asset_name` varchar(100) NOT NULL,
  `asset_type` varchar(50) NOT NULL,
  `quantity` int NOT NULL,
  `did` int NOT NULL,
  `locationtype` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `roomnum` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'NA',
  `descrip` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `status` varchar(20) DEFAULT 'Pending',
  `date` int NOT NULL,
  `month` int NOT NULL,
  `year` int NOT NULL,
  PRIMARY KEY (`sr_id`),
  KEY `did` (`did`),
  KEY `username` (`username`)
) ENGINE=MyISAM AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stlog`
--

DROP TABLE IF EXISTS `stlog`;
CREATE TABLE IF NOT EXISTS `stlog` (
  `uid` int NOT NULL AUTO_INCREMENT,
  `username` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `uemail` varchar(456) NOT NULL,
  `uphone` varchar(10) NOT NULL,
  `upass` varchar(34) NOT NULL,
  PRIMARY KEY (`uid`)
) ENGINE=MyISAM AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
CREATE TABLE IF NOT EXISTS `students` (
  `sid` int NOT NULL AUTO_INCREMENT,
  `sname` varchar(43) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `did` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `course_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `semester` int NOT NULL,
  `rollNumber` varchar(42) NOT NULL,
  `phone` varchar(10) NOT NULL,
  `email` varchar(123) NOT NULL,
  PRIMARY KEY (`sid`)
) ENGINE=MyISAM AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teacher`
--

DROP TABLE IF EXISTS `teacher`;
CREATE TABLE IF NOT EXISTS `teacher` (
  `tid` int NOT NULL AUTO_INCREMENT,
  `tname` varchar(50) NOT NULL,
  `did` int NOT NULL,
  `tphone` varchar(10) NOT NULL,
  `temail` varchar(67) NOT NULL,
  PRIMARY KEY (`tid`)
) ENGINE=MyISAM AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: salma_project
-- ------------------------------------------------------
-- Server version	8.0.46

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','admin','user','professeur') NOT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `subject_id` int DEFAULT NULL,
  `gender` enum('MALE','FEMALE') DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `specialty` varchar(120) DEFAULT NULL,
  `primary_school` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `school_id` (`school_id`),
  KEY `users_subject_id_fk` (`subject_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_subject_id_fk` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,NULL,'Owner','EduFlow','owner@eduflow.com','$2y$10$XqC2r7yA1DsOZLbOT26c7.BjeIZwBCaQkw7ARzfSjThu29oLdhw06','super_admin','ACTIVE',NULL,NULL,NULL,NULL,NULL,NULL,'2026-04-16 23:42:55'),(2,1,'YASSINE','BENMANSOUR','yassine.benmansour@miranda.com','$2y$10$ad5cZzLMfaVB7sCADtZZfeXrtK0nTFRGmgms3axprgTB0UFG6usvm','admin','ACTIVE',NULL,NULL,NULL,NULL,NULL,NULL,'2026-04-17 00:18:59'),(4,1,'salma','salma','salma.salma@miranda.com','$2y$12$FctCTb5ldQtSI2kJWJClSOj520X5RacLbyD5uaMBOm8W1ceGRFnsi','professeur','ACTIVE',NULL,'FEMALE','098','berrchid',NULL,'MIRANDA','2026-09-01 08:17:52');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teacher_subjects`
--

DROP TABLE IF EXISTS `teacher_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teacher_subjects` (
  `teacher_id` int NOT NULL,
  `subject_id` int NOT NULL,
  PRIMARY KEY (`teacher_id`,`subject_id`),
  KEY `teacher_subjects_subject_id_idx` (`subject_id`),
  CONSTRAINT `teacher_subjects_subject_fk` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teacher_subjects_teacher_fk` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teacher_subjects`
--

LOCK TABLES `teacher_subjects` WRITE;
/*!40000 ALTER TABLE `teacher_subjects` DISABLE KEYS */;
INSERT INTO `teacher_subjects` VALUES (18,2),(20,3),(19,4),(23,6),(22,8),(24,21),(25,21);
/*!40000 ALTER TABLE `teacher_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teacher_class_levels`
--

DROP TABLE IF EXISTS `teacher_class_levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teacher_class_levels` (
  `teacher_id` int NOT NULL,
  `class_level_id` int NOT NULL,
  PRIMARY KEY (`teacher_id`,`class_level_id`),
  KEY `teacher_class_levels_class_level_id_idx` (`class_level_id`),
  CONSTRAINT `teacher_class_levels_class_level_fk` FOREIGN KEY (`class_level_id`) REFERENCES `class_levels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teacher_class_levels_teacher_fk` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teacher_class_levels`
--

LOCK TABLES `teacher_class_levels` WRITE;
/*!40000 ALTER TABLE `teacher_class_levels` DISABLE KEYS */;
INSERT INTO `teacher_class_levels` VALUES (18,10),(19,10),(20,10),(22,10),(23,10),(24,10),(18,11),(19,11),(20,11),(22,11),(23,11),(24,11),(18,12),(19,12),(20,12),(22,12),(23,12),(25,12),(18,13),(19,13),(20,13),(22,13),(23,13),(25,13),(18,14),(19,14),(20,14),(22,14),(23,14),(25,14),(18,15),(19,15),(20,15),(22,15),(23,15),(25,15),(23,16),(23,17),(23,18),(23,19);
/*!40000 ALTER TABLE `teacher_class_levels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subjects`
--

DROP TABLE IF EXISTS `subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subjects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `code` varchar(20) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5202 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subjects`
--

LOCK TABLES `subjects` WRITE;
/*!40000 ALTER TABLE `subjects` DISABLE KEYS */;
INSERT INTO `subjects` VALUES (1,'Français','FR',1,'ACTIVE','2026-08-28 11:17:37',NULL),(2,'Arabe','AR',2,'ACTIVE','2026-08-28 11:17:37',NULL),(3,'Anglais','ANG',3,'ACTIVE','2026-08-28 11:17:37',NULL),(4,'Histoire-Géographie','H.G',5,'ACTIVE','2026-08-28 11:17:37','2026-08-28 15:37:39'),(5,'Éducation Islamique','I.I',6,'ACTIVE','2026-08-28 11:17:37','2026-08-28 15:37:39'),(6,'Sport / EPS','EPS',7,'ACTIVE','2026-08-28 11:17:37','2026-08-28 15:37:39'),(7,'SVT','SVT',8,'ACTIVE','2026-08-28 11:17:37','2026-08-28 15:37:39'),(8,'Physique-Chimie','PC',9,'ACTIVE','2026-08-28 11:17:37','2026-08-28 15:37:39'),(9,'Informatique','Info',10,'ACTIVE','2026-08-28 11:17:37','2026-08-28 15:37:39'),(10,'Philosophie','PHILO',11,'ACTIVE','2026-08-28 11:17:37','2026-08-28 15:37:39'),(21,'Mathématiques','MATH',4,'ACTIVE','2026-08-28 15:36:40',NULL),(741,'Éducation Islamique','II',6,'ACTIVE','2026-08-28 23:25:07',NULL);
/*!40000 ALTER TABLE `subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schedules`
--

DROP TABLE IF EXISTS `schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schedules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `class_level_id` int DEFAULT NULL,
  `subject_id` int DEFAULT NULL,
  `subject` varchar(120) NOT NULL,
  `teacher_id` int DEFAULT NULL,
  `teacher_name` varchar(120) DEFAULT NULL,
  `room` varchar(80) DEFAULT NULL,
  `is_external` tinyint(1) NOT NULL DEFAULT '0',
  `schedule_type` enum('eduflow_course','external_busy') NOT NULL DEFAULT 'eduflow_course',
  `year_value` int DEFAULT NULL,
  `week_number` tinyint DEFAULT NULL,
  `day_of_week` enum('MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY') NOT NULL,
  `day_order` tinyint NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `notes` text,
  `status` enum('ACTIVE','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `schedules_school_day_idx` (`school_id`,`year_value`,`week_number`,`day_order`,`start_time`),
  KEY `schedules_class_day_idx` (`class_level_id`,`year_value`,`week_number`,`day_of_week`,`start_time`),
  KEY `schedules_teacher_day_idx` (`teacher_id`,`year_value`,`week_number`,`day_of_week`,`start_time`),
  KEY `schedules_school_week_day_idx` (`school_id`,`year_value`,`week_number`,`day_order`,`start_time`),
  KEY `schedules_class_week_day_idx` (`class_level_id`,`year_value`,`week_number`,`day_of_week`,`start_time`),
  KEY `schedules_subject_id_fk` (`subject_id`),
  CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `schedules_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `schedules_ibfk_3` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `schedules_ibfk_4` FOREIGN KEY (`class_level_id`) REFERENCES `class_levels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `schedules_subject_id_fk` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `schedules_teacher_id_fk` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schedules`
--

LOCK TABLES `schedules` WRITE;
/*!40000 ALTER TABLE `schedules` DISABLE KEYS */;
INSERT INTO `schedules` VALUES (14,1,NULL,NULL,'Ailleurs',NULL,'sysy rahib',NULL,1,'external_busy',2026,1,'MONDAY',1,'11:30:00','12:30:00',NULL,'ACTIVE','2026-08-29 11:32:37'),(15,1,NULL,NULL,'Ailleurs',NULL,'sysy rahib',NULL,1,'external_busy',2026,1,'MONDAY',1,'08:30:00','09:30:00',NULL,'ACTIVE','2026-08-29 11:32:51'),(16,1,NULL,NULL,'Ailleurs',NULL,'ali -',NULL,1,'external_busy',2026,1,'MONDAY',1,'09:30:00','10:30:00',NULL,'ACTIVE','2026-08-29 11:35:23'),(19,1,NULL,NULL,'Ailleurs',NULL,'ali -',NULL,1,'external_busy',2026,1,'MONDAY',1,'11:30:00','12:30:00',NULL,'ACTIVE','2026-08-29 11:49:27'),(33,1,18,1,'Français',NULL,'hatim -',NULL,0,'eduflow_course',2026,2,'MONDAY',1,'08:30:00','09:30:00',NULL,'ACTIVE','2026-08-31 09:38:23'),(34,1,18,1,'Français',NULL,'salma -',NULL,0,'eduflow_course',2026,2,'MONDAY',1,'10:30:00','11:30:00',NULL,'ACTIVE','2026-08-31 09:38:28'),(35,1,18,1,'Français',NULL,'sysy rahib',NULL,0,'eduflow_course',2026,2,'THURSDAY',4,'11:30:00','12:30:00',NULL,'ACTIVE','2026-08-31 09:38:32'),(37,1,NULL,NULL,'Ailleurs',NULL,'sysy rahib',NULL,1,'external_busy',2026,2,'MONDAY',1,'09:30:00','10:30:00',NULL,'ACTIVE','2026-08-31 09:42:20'),(48,1,10,21,'Mathématiques',24,'Omar SOBHI',NULL,0,'eduflow_course',2026,2,'MONDAY',1,'08:30:00','09:30:00',NULL,'ACTIVE','2026-08-31 11:26:57'),(49,1,10,6,'Sport / EPS',23,'Mouna MANDALI',NULL,0,'eduflow_course',2026,2,'TUESDAY',2,'08:30:00','09:30:00',NULL,'ACTIVE','2026-08-31 11:29:43');
/*!40000 ALTER TABLE `schedules` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-01 13:25:09

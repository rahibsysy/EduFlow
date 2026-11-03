-- MIRANDA canonical baseline candidate: schema and technical references only.
-- Generated from the audited MySQL schema; no school, user, student, family, personnel or financial data.
-- Not discovered by the current migration runner and not to be applied to an existing database.

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
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_years` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `label` varchar(30) NOT NULL DEFAULT '',
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE','PLANNED','CLOSED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(30) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `academic_years_school_label_unique` (`school_id`,`label`),
  UNIQUE KEY `academic_years_school_name_unique` (`school_id`,`name`),
  CONSTRAINT `academic_years_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `eduflow_academic_year_alias_insert` BEFORE INSERT ON `academic_years` FOR EACH ROW BEGIN
                IF NEW.label IS NULL OR NEW.label='' THEN SET NEW.label=NEW.name; END IF;
                IF NEW.name IS NULL OR NEW.name='' THEN SET NEW.name=REPLACE(NEW.label,'/','-'); END IF;
                SET NEW.start_date=COALESCE(NEW.start_date,NEW.starts_on), NEW.starts_on=COALESCE(NEW.starts_on,NEW.start_date);
                SET NEW.end_date=COALESCE(NEW.end_date,NEW.ends_on), NEW.ends_on=COALESCE(NEW.ends_on,NEW.end_date);
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `eduflow_academic_year_alias_update` BEFORE UPDATE ON `academic_years` FOR EACH ROW BEGIN
                IF NOT (NEW.label <=> OLD.label) THEN SET NEW.name=REPLACE(NEW.label,'/','-'); ELSEIF NOT (NEW.name <=> OLD.name) THEN SET NEW.label=NEW.name; END IF;
                IF NOT (NEW.starts_on <=> OLD.starts_on) THEN SET NEW.start_date=NEW.starts_on; ELSEIF NOT (NEW.start_date <=> OLD.start_date) THEN SET NEW.starts_on=NEW.start_date; END IF;
                IF NOT (NEW.ends_on <=> OLD.ends_on) THEN SET NEW.end_date=NEW.ends_on; ELSEIF NOT (NEW.end_date <=> OLD.end_date) THEN SET NEW.ends_on=NEW.end_date; END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assessments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `teacher_assignment_id` int NOT NULL,
  `grading_period_id` int NOT NULL,
  `title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `assessment_type` enum('CONTROL','HOMEWORK','EXAM') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CONTROL',
  `assessment_date` date NOT NULL,
  `max_score` decimal(6,2) NOT NULL DEFAULT '20.00',
  `coefficient` decimal(5,2) NOT NULL DEFAULT '1.00',
  `status` enum('DRAFT','PUBLISHED','LOCKED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'DRAFT',
  `created_by` int NOT NULL,
  `published_at` datetime DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `assessments_assignment_period_idx` (`teacher_assignment_id`,`grading_period_id`,`assessment_date`),
  KEY `assessments_school_status_idx` (`school_id`,`status`),
  KEY `grading_period_id` (`grading_period_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `assessments_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assessments_ibfk_2` FOREIGN KEY (`teacher_assignment_id`) REFERENCES `teacher_assignments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `assessments_ibfk_3` FOREIGN KEY (`grading_period_id`) REFERENCES `grading_periods` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `assessments_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `assessments_coefficient_check` CHECK ((`coefficient` > 0)),
  CONSTRAINT `assessments_score_check` CHECK ((`max_score` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `schedule_id` int DEFAULT NULL,
  `teacher_id` int NOT NULL,
  `class_level_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `session_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('COMPLETED','CANCELLED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'COMPLETED',
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendance_sessions_schedule_date_unique` (`schedule_id`,`session_date`),
  KEY `attendance_sessions_teacher_date_idx` (`teacher_id`,`session_date`),
  KEY `attendance_sessions_class_date_idx` (`class_level_id`,`session_date`),
  KEY `school_id` (`school_id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `attendance_sessions_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_sessions_ibfk_2` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendance_sessions_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `attendance_sessions_ibfk_4` FOREIGN KEY (`class_level_id`) REFERENCES `class_levels` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `attendance_sessions_ibfk_5` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `charge_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `code` varchar(60) NOT NULL,
  `label` varchar(120) NOT NULL,
  `description` text,
  `default_amount` decimal(12,2) DEFAULT NULL,
  `periodicity` enum('MONTHLY','ANNUAL','ONE_TIME','SELECTED_MONTHS','CUSTOM') NOT NULL DEFAULT 'ONE_TIME',
  `status` enum('ACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `charge_categories_school_code_unique` (`school_id`,`code`),
  KEY `charge_categories_school_status_idx` (`school_id`,`status`),
  CONSTRAINT `charge_categories_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `charge_discount_targets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `discount_id` int NOT NULL,
  `student_id` int NOT NULL,
  `charge_category_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `charge_discount_targets_unique` (`discount_id`,`student_id`,`charge_category_id`),
  KEY `charge_discount_targets_student_idx` (`student_id`),
  KEY `charge_discount_targets_category_fk` (`charge_category_id`),
  CONSTRAINT `charge_discount_targets_category_fk` FOREIGN KEY (`charge_category_id`) REFERENCES `charge_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `charge_discount_targets_discount_fk` FOREIGN KEY (`discount_id`) REFERENCES `charge_discounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `charge_discount_targets_student_fk` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `charge_discounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `discount_type` enum('PERCENTAGE','FIXED') NOT NULL,
  `discount_value` decimal(12,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `status` enum('ACTIVE','EXPIRED','REPLACED','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
  `created_by` int DEFAULT NULL,
  `replaced_by` int DEFAULT NULL,
  `cancellation_reason` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `charge_discounts_school_status_idx` (`school_id`,`status`),
  KEY `charge_discounts_created_by_fk` (`created_by`),
  CONSTRAINT `charge_discounts_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `charge_discounts_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_levels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `level_name` varchar(180) DEFAULT NULL,
  `group_name` varchar(100) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_levels_school_name_unique` (`school_id`,`name`),
  CONSTRAINT `class_levels_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `enrollments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `academic_year_id` int NOT NULL,
  `student_id` int NOT NULL,
  `class_level_id` int NOT NULL,
  `enrollment_date` date NOT NULL,
  `status` enum('ACTIVE','TRANSFERRED','WITHDRAWN','COMPLETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `enrollments_student_year_unique` (`student_id`,`academic_year_id`),
  KEY `enrollments_school_year_class_idx` (`school_id`,`academic_year_id`,`class_level_id`,`status`),
  KEY `enrollments_academic_year_id_fk` (`academic_year_id`),
  KEY `enrollments_class_level_id_fk` (`class_level_id`),
  CONSTRAINT `enrollments_academic_year_id_fk` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `enrollments_class_level_id_fk` FOREIGN KEY (`class_level_id`) REFERENCES `class_levels` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `enrollments_school_id_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `enrollments_student_id_fk` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `families` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `family_reference` varchar(80) NOT NULL,
  `family_name` varchar(180) NOT NULL,
  `primary_guardian_id` int DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `families_school_reference_unique` (`school_id`,`family_reference`),
  KEY `families_school_name_idx` (`school_id`,`family_name`),
  KEY `families_primary_guardian_fk` (`primary_guardian_id`),
  CONSTRAINT `families_primary_guardian_fk` FOREIGN KEY (`primary_guardian_id`) REFERENCES `guardians` (`id`) ON DELETE SET NULL,
  CONSTRAINT `families_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `family_discount_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `family_discount_id` int NOT NULL,
  `school_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `before_data` json DEFAULT NULL,
  `after_data` json DEFAULT NULL,
  `reason` text,
  `changed_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `family_discount_history_discount_idx` (`family_discount_id`),
  KEY `family_discount_history_school_fk` (`school_id`),
  KEY `family_discount_history_user_fk` (`changed_by`),
  CONSTRAINT `family_discount_history_discount_fk` FOREIGN KEY (`family_discount_id`) REFERENCES `family_discounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `family_discount_history_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `family_discount_history_user_fk` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `family_discount_impacts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `family_discount_id` int NOT NULL,
  `school_id` int NOT NULL,
  `student_financial_item_id` int NOT NULL,
  `student_id` int NOT NULL,
  `previous_total_amount` decimal(12,2) NOT NULL,
  `previous_discount_amount` decimal(12,2) NOT NULL,
  `previous_final_amount` decimal(12,2) NOT NULL,
  `applied_discount_amount` decimal(12,2) NOT NULL,
  `final_amount` decimal(12,2) NOT NULL,
  `status` enum('APPLIED','REVERSED') NOT NULL DEFAULT 'APPLIED',
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reversed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `family_discount_impacts_discount_item_unique` (`family_discount_id`,`student_financial_item_id`),
  KEY `family_discount_impacts_discount_status_idx` (`family_discount_id`,`status`),
  KEY `family_discount_impacts_school_item_status_idx` (`school_id`,`student_financial_item_id`,`status`),
  KEY `family_discount_impacts_item_fk` (`student_financial_item_id`),
  KEY `family_discount_impacts_student_fk` (`student_id`),
  CONSTRAINT `family_discount_impacts_discount_fk` FOREIGN KEY (`family_discount_id`) REFERENCES `family_discounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `family_discount_impacts_item_fk` FOREIGN KEY (`student_financial_item_id`) REFERENCES `student_financial_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `family_discount_impacts_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `family_discount_impacts_student_fk` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `family_discounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `family_id` int NOT NULL,
  `discount_type` enum('PERCENTAGE','FIXED') NOT NULL,
  `discount_value` decimal(12,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `status` enum('ACTIVE','CANCELLED','REPLACED') NOT NULL DEFAULT 'ACTIVE',
  `created_by` int DEFAULT NULL,
  `cancelled_by` int DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `family_discounts_school_family_status_idx` (`school_id`,`family_id`,`status`),
  KEY `family_discounts_family_fk` (`family_id`),
  KEY `family_discounts_created_by_fk` (`created_by`),
  KEY `family_discounts_cancelled_by_fk` (`cancelled_by`),
  CONSTRAINT `family_discounts_cancelled_by_fk` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `family_discounts_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `family_discounts_family_fk` FOREIGN KEY (`family_id`) REFERENCES `families` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `family_discounts_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `grade_history` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `grade_id` int DEFAULT NULL,
  `assessment_id` int NOT NULL,
  `enrollment_id` int NOT NULL,
  `actor_id` int NOT NULL,
  `action` enum('CREATED','UPDATED','PUBLISHED','LOCKED','UNLOCKED') COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_value` json DEFAULT NULL,
  `new_value` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `grade_history_assessment_created_idx` (`assessment_id`,`created_at`),
  KEY `school_id` (`school_id`),
  KEY `grade_id` (`grade_id`),
  KEY `enrollment_id` (`enrollment_id`),
  KEY `actor_id` (`actor_id`),
  CONSTRAINT `grade_history_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grade_history_ibfk_2` FOREIGN KEY (`grade_id`) REFERENCES `student_grades` (`id`) ON DELETE SET NULL,
  CONSTRAINT `grade_history_ibfk_3` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grade_history_ibfk_4` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grade_history_ibfk_5` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `grading_periods` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `academic_year_id` int NOT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('ACTIVE','CLOSED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grading_periods_year_code_unique` (`academic_year_id`,`code`),
  KEY `grading_periods_school_status_idx` (`school_id`,`status`),
  CONSTRAINT `grading_periods_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grading_periods_ibfk_2` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `guardians` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `legacy_parent_id` int DEFAULT NULL,
  `full_name` varchar(180) NOT NULL,
  `phone_primary` varchar(30) DEFAULT NULL,
  `phone_secondary` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `cin` varchar(80) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `profession` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `guardians_legacy_parent_unique` (`legacy_parent_id`),
  KEY `guardians_school_phone_idx` (`school_id`,`phone_primary`),
  CONSTRAINT `guardians_legacy_parent_fk` FOREIGN KEY (`legacy_parent_id`) REFERENCES `parents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `guardians_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `level_fee_prices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `class_level_id` int NOT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `monthly_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `transport_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `registration_insurance_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `level_fee_prices_unique` (`school_id`,`class_level_id`,`school_year`),
  KEY `class_level_id` (`class_level_id`),
  CONSTRAINT `level_fee_prices_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `level_fee_prices_ibfk_2` FOREIGN KEY (`class_level_id`) REFERENCES `class_levels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `local_sync_academic_years` (
  `id` int NOT NULL,
  `status` varchar(20) NOT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `local_sync_steps` (
  `version` varchar(100) NOT NULL,
  `completed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `monthly_fees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `student_id` int NOT NULL,
  `month_label` varchar(20) NOT NULL,
  `year_value` int NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `amount_paid` decimal(10,2) DEFAULT '0.00',
  `remaining_amount` decimal(10,2) DEFAULT '0.00',
  `status` enum('PAID','PARTIAL','UNPAID') DEFAULT 'UNPAID',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `monthly_fees_school_status_idx` (`school_id`,`status`),
  KEY `monthly_fees_student_status_idx` (`student_id`,`status`),
  KEY `monthly_fees_school_period_idx` (`school_id`,`year_value`,`month_label`),
  CONSTRAINT `monthly_fees_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `monthly_fees_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `school_id` (`school_id`),
  CONSTRAINT `parents_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_allocations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `payment_id` int NOT NULL,
  `student_financial_item_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_allocations_payment_item_unique` (`payment_id`,`student_financial_item_id`),
  KEY `payment_allocations_school_item_idx` (`school_id`,`student_financial_item_id`),
  KEY `payment_allocations_school_payment_idx` (`school_id`,`payment_id`),
  CONSTRAINT `payment_allocations_item_school_fk` FOREIGN KEY (`school_id`, `student_financial_item_id`) REFERENCES `student_financial_items` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `payment_allocations_payment_school_fk` FOREIGN KEY (`school_id`, `payment_id`) REFERENCES `payments` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `payment_allocations_amount_positive` CHECK ((`amount` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_methods` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `student_id` int NOT NULL,
  `monthly_fee_id` int DEFAULT NULL,
  `receipt_number` varchar(40) DEFAULT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `fee_total_at_payment` decimal(10,2) DEFAULT NULL,
  `paid_before_payment` decimal(10,2) DEFAULT NULL,
  `remaining_after_payment` decimal(10,2) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `payment_method_id` int DEFAULT NULL,
  `payment_method` varchar(50) NOT NULL,
  `status` enum('COMPLETED','CANCELLED') NOT NULL DEFAULT 'COMPLETED',
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by_user_id` int DEFAULT NULL,
  `cancellation_reason` text,
  `issued_by_user_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_school_receipt_unique` (`school_id`,`receipt_number`),
  KEY `payments_school_date_idx` (`school_id`,`payment_date`),
  KEY `payments_student_idx` (`student_id`),
  KEY `payments_monthly_fee_idx` (`monthly_fee_id`),
  KEY `payment_method_id` (`payment_method_id`),
  KEY `payments_issued_by_user_fk` (`issued_by_user_id`),
  KEY `payments_cancelled_by_user_fk` (`cancelled_by_user_id`),
  KEY `payments_school_id_id_idx` (`school_id`,`id`),
  CONSTRAINT `payments_cancelled_by_user_fk` FOREIGN KEY (`cancelled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`monthly_fee_id`) REFERENCES `monthly_fees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_4` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_issued_by_user_fk` FOREIGN KEY (`issued_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(100) NOT NULL,
  `label` varchar(160) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personnel_class_levels` (
  `personnel_id` int NOT NULL,
  `school_id` int NOT NULL,
  `class_level_id` int NOT NULL,
  PRIMARY KEY (`personnel_id`,`class_level_id`),
  KEY `personnel_class_levels_school_idx` (`school_id`),
  KEY `personnel_class_levels_level_idx` (`class_level_id`),
  CONSTRAINT `personnel_class_levels_level_fk` FOREIGN KEY (`class_level_id`) REFERENCES `class_levels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `personnel_class_levels_personnel_fk` FOREIGN KEY (`personnel_id`) REFERENCES `personnel_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `personnel_class_levels_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personnel_profiles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `personnel_number` varchar(40) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `first_name_ar` varchar(100) DEFAULT NULL,
  `last_name_ar` varchar(100) DEFAULT NULL,
  `cin` varchar(80) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `birth_place` varchar(150) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `emergency_contact_name` varchar(180) DEFAULT NULL,
  `emergency_contact_phone` varchar(30) DEFAULT NULL,
  `personnel_type` enum('TEACHER','PROFESSOR','ADMINISTRATIVE_STAFF') NOT NULL,
  `job_title` varchar(150) DEFAULT NULL,
  `job_function` varchar(150) DEFAULT NULL,
  `service_assignment` varchar(150) DEFAULT NULL,
  `entry_date` date DEFAULT NULL,
  `contract_type` enum('CDI','CDD','VACATAIRE','STAGE','AUTRE') DEFAULT NULL,
  `status` enum('ACTIF','EN_CONGE','ARCHIVE') NOT NULL DEFAULT 'ACTIF',
  `main_degree` varchar(180) DEFAULT NULL,
  `specialty` varchar(180) DEFAULT NULL,
  `work_time` enum('TEMPS_PLEIN','TEMPS_PARTIEL') DEFAULT NULL,
  `administrative_department` varchar(150) DEFAULT NULL,
  `primary_mission` text,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personnel_school_number_unique` (`school_id`,`personnel_number`),
  UNIQUE KEY `personnel_school_cin_unique` (`school_id`,`cin`),
  UNIQUE KEY `personnel_user_unique` (`user_id`),
  KEY `personnel_school_type_status` (`school_id`,`personnel_type`,`status`),
  CONSTRAINT `personnel_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `personnel_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personnel_subjects` (
  `personnel_id` int NOT NULL,
  `school_id` int NOT NULL,
  `subject_id` int NOT NULL,
  PRIMARY KEY (`personnel_id`,`subject_id`),
  KEY `personnel_subjects_school_idx` (`school_id`),
  KEY `personnel_subjects_subject_idx` (`subject_id`),
  CONSTRAINT `personnel_subjects_personnel_fk` FOREIGN KEY (`personnel_id`) REFERENCES `personnel_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `personnel_subjects_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `personnel_subjects_subject_fk` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `role` varchar(30) NOT NULL,
  `permission_id` int NOT NULL,
  PRIMARY KEY (`role`,`permission_id`),
  KEY `role_permissions_permission_fk` (`permission_id`),
  CONSTRAINT `role_permissions_permission_fk` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schema_migrations` (
  `version` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `checksum` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `execution_type` enum('APPLIED','BASELINED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'APPLIED',
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_cycles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `code` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `school_cycles_school_code_unique` (`school_id`,`code`),
  CONSTRAINT `school_cycles_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_fee_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `fee_code` varchar(60) NOT NULL,
  `label` varchar(120) NOT NULL,
  `default_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `billing_month` tinyint DEFAULT NULL,
  `applies_cycle_id` int DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  PRIMARY KEY (`id`),
  UNIQUE KEY `school_fee_settings_unique` (`school_id`,`fee_code`),
  KEY `applies_cycle_id` (`applies_cycle_id`),
  CONSTRAINT `school_fee_settings_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `school_fee_settings_ibfk_2` FOREIGN KEY (`applies_cycle_id`) REFERENCES `school_cycles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_levels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `cycle_id` int DEFAULT NULL,
  `code` varchar(50) NOT NULL,
  `label` varchar(150) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `school_levels_school_code_unique` (`school_id`,`code`),
  KEY `school_levels_cycle_fk` (`cycle_id`),
  CONSTRAINT `school_levels_cycle_fk` FOREIGN KEY (`cycle_id`) REFERENCES `school_cycles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `school_levels_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `school_settings_school_key_unique` (`school_id`,`setting_key`),
  KEY `school_settings_user_fk` (`updated_by`),
  CONSTRAINT `school_settings_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `school_settings_user_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schools` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `code` varchar(50) NOT NULL,
  `slug` varchar(160) NOT NULL,
  `email_domain` varchar(160) NOT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `phone_secondary` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `administrative_info` varchar(500) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `primary_color` varchar(20) NOT NULL DEFAULT '#1E3A8A',
  `secondary_color` varchar(20) NOT NULL DEFAULT '#22C55E',
  `currency` varchar(10) NOT NULL DEFAULT 'MAD',
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  UNIQUE KEY `slug` (`slug`),
  UNIQUE KEY `email_domain` (`email_domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_absences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `attendance_session_id` int NOT NULL,
  `enrollment_id` int NOT NULL,
  `status` enum('ABSENT','EXCUSED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ABSENT',
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_absences_session_enrollment_unique` (`attendance_session_id`,`enrollment_id`),
  KEY `student_absences_enrollment_idx` (`enrollment_id`),
  KEY `school_id` (`school_id`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `student_absences_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_absences_ibfk_2` FOREIGN KEY (`attendance_session_id`) REFERENCES `attendance_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_absences_ibfk_3` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_absences_ibfk_4` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_financial_item_details` (
  `student_financial_item_id` int NOT NULL,
  `school_id` int NOT NULL,
  `charge_category_id` int DEFAULT NULL,
  `billing_month` tinyint DEFAULT NULL,
  `year_value` int DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `original_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_type` enum('PERCENTAGE','FIXED') DEFAULT NULL,
  `discount_value` decimal(12,2) DEFAULT NULL,
  `final_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `notes` text,
  `modified_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_financial_item_id`),
  KEY `student_financial_item_details_category_idx` (`school_id`,`charge_category_id`),
  KEY `student_financial_item_details_period_idx` (`school_id`,`year_value`,`billing_month`),
  KEY `student_financial_item_details_category_fk` (`charge_category_id`),
  KEY `student_financial_item_details_user_fk` (`modified_by`),
  CONSTRAINT `student_financial_item_details_category_fk` FOREIGN KEY (`charge_category_id`) REFERENCES `charge_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_financial_item_details_item_fk` FOREIGN KEY (`student_financial_item_id`) REFERENCES `student_financial_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_financial_item_details_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_financial_item_details_user_fk` FOREIGN KEY (`modified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_financial_item_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `student_financial_item_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `before_data` json DEFAULT NULL,
  `after_data` json DEFAULT NULL,
  `reason` text,
  `changed_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `student_financial_item_history_item_idx` (`student_financial_item_id`),
  KEY `student_financial_item_history_school_fk` (`school_id`),
  KEY `student_financial_item_history_user_fk` (`changed_by`),
  CONSTRAINT `student_financial_item_history_item_fk` FOREIGN KEY (`student_financial_item_id`) REFERENCES `student_financial_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_financial_item_history_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_financial_item_history_user_fk` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_financial_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `student_id` int NOT NULL,
  `academic_year_id` int DEFAULT NULL,
  `item_code` varchar(60) NOT NULL,
  `label` varchar(120) NOT NULL,
  `billing_month` tinyint DEFAULT NULL,
  `quantity` decimal(8,2) NOT NULL DEFAULT '1.00',
  `unit_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('UNPAID','PARTIAL','PAID','EXEMPT') NOT NULL DEFAULT 'UNPAID',
  `justification` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_financial_items_single_charge` (`school_id`,`student_id`,`academic_year_id`,`item_code`,`billing_month`),
  KEY `student_financial_items_student_idx` (`school_id`,`student_id`),
  KEY `student_id` (`student_id`),
  KEY `academic_year_id` (`academic_year_id`),
  KEY `created_by` (`created_by`),
  KEY `student_financial_items_school_id_id_idx` (`school_id`,`id`),
  CONSTRAINT `student_financial_items_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_financial_items_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_financial_items_ibfk_3` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_financial_items_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_grades` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `assessment_id` int NOT NULL,
  `enrollment_id` int NOT NULL,
  `score` decimal(6,2) DEFAULT NULL,
  `attendance_status` enum('PRESENT','ABSENT','EXCUSED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PRESENT',
  `remark` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_grades_assessment_enrollment_unique` (`assessment_id`,`enrollment_id`),
  KEY `student_grades_enrollment_idx` (`enrollment_id`),
  KEY `school_id` (`school_id`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `student_grades_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_grades_ibfk_2` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_grades_ibfk_3` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_grades_ibfk_4` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `student_grades_score_check` CHECK (((`score` is null) or (`score` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_guardians` (
  `student_id` int NOT NULL,
  `guardian_id` int NOT NULL,
  `school_id` int NOT NULL,
  `relationship_type` varchar(80) NOT NULL,
  `is_financial_responsible` tinyint(1) NOT NULL DEFAULT '0',
  `is_emergency_contact` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_id`,`guardian_id`),
  KEY `student_guardians_school_guardian_idx` (`school_id`,`guardian_id`),
  KEY `student_guardians_guardian_fk` (`guardian_id`),
  CONSTRAINT `student_guardians_guardian_fk` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_guardians_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_guardians_student_fk` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_status_histories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `student_id` int NOT NULL,
  `previous_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) NOT NULL,
  `reason` text,
  `changed_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `student_status_histories_school_student_idx` (`school_id`,`student_id`),
  KEY `student_status_histories_student_fk` (`student_id`),
  KEY `student_status_histories_user_fk` (`changed_by`),
  CONSTRAINT `student_status_histories_school_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_status_histories_student_fk` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_status_histories_user_fk` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `students` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `internal_number` varchar(80) DEFAULT NULL,
  `massar_code` varchar(80) DEFAULT NULL,
  `cne` varchar(80) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `family_id` int DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `first_name_ar` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `last_name_ar` varchar(100) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `birth_place` varchar(150) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `entry_date` date DEFAULT NULL,
  `previous_school` varchar(180) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `class_level` varchar(100) NOT NULL,
  `class_name` varchar(100) DEFAULT NULL,
  `class_level_id` int DEFAULT NULL,
  `parent_name` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `monthly_amount` decimal(10,2) NOT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `school_year` varchar(20) DEFAULT NULL,
  `academic_year_id` int DEFAULT NULL,
  `cycle_id` int DEFAULT NULL,
  `school_level_id` int DEFAULT NULL,
  `uses_transport` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('PRE_REGISTERED','REGISTERED','WAITING_LIST','CANCELLED','ARCHIVED') NOT NULL DEFAULT 'PRE_REGISTERED',
  `archived_at` timestamp NULL DEFAULT NULL,
  `archived_by` int DEFAULT NULL,
  `archive_reason` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `students_school_internal_number_unique` (`school_id`,`internal_number`),
  UNIQUE KEY `students_school_massar_code_unique` (`school_id`,`massar_code`),
  KEY `school_id` (`school_id`),
  KEY `parent_id` (`parent_id`),
  KEY `class_level_id` (`class_level_id`),
  KEY `students_school_status_idx` (`school_id`,`status`),
  KEY `students_academic_year_fk` (`academic_year_id`),
  KEY `students_cycle_fk` (`cycle_id`),
  KEY `students_school_level_fk` (`school_level_id`),
  KEY `students_archived_by_fk` (`archived_by`),
  KEY `students_family_id_idx` (`family_id`),
  KEY `students_parent_id_idx` (`parent_id`),
  CONSTRAINT `students_academic_year_fk` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `students_archived_by_fk` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `students_cycle_fk` FOREIGN KEY (`cycle_id`) REFERENCES `school_cycles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `students_family_fk` FOREIGN KEY (`family_id`) REFERENCES `families` (`id`) ON DELETE SET NULL,
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `students_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `students_ibfk_3` FOREIGN KEY (`class_level_id`) REFERENCES `class_levels` (`id`) ON DELETE SET NULL,
  CONSTRAINT `students_school_level_fk` FOREIGN KEY (`school_level_id`) REFERENCES `school_levels` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subject_class_levels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `subject_id` int NOT NULL,
  `class_level_id` int NOT NULL,
  `weekly_hours` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subject_class_levels_unique` (`subject_id`,`class_level_id`),
  KEY `class_level_id` (`class_level_id`),
  CONSTRAINT `subject_class_levels_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subject_class_levels_ibfk_2` FOREIGN KEY (`class_level_id`) REFERENCES `class_levels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teacher_assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `academic_year_id` int NOT NULL,
  `teacher_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `class_level_id` int NOT NULL,
  `weekly_hours` tinyint unsigned NOT NULL DEFAULT '0',
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teacher_assignments_scope_unique` (`academic_year_id`,`teacher_id`,`subject_id`,`class_level_id`),
  KEY `teacher_assignments_teacher_year_idx` (`teacher_id`,`academic_year_id`,`status`),
  KEY `teacher_assignments_class_year_idx` (`class_level_id`,`academic_year_id`,`status`),
  KEY `teacher_assignments_school_id_fk` (`school_id`),
  KEY `teacher_assignments_subject_id_fk` (`subject_id`),
  CONSTRAINT `teacher_assignments_academic_year_id_fk` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `teacher_assignments_class_level_id_fk` FOREIGN KEY (`class_level_id`) REFERENCES `class_levels` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `teacher_assignments_school_id_fk` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teacher_assignments_subject_id_fk` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `teacher_assignments_teacher_id_fk` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_permissions` (
  `user_id` int NOT NULL,
  `permission_id` int NOT NULL,
  PRIMARY KEY (`user_id`,`permission_id`),
  KEY `user_permissions_permission_idx` (`permission_id`),
  CONSTRAINT `user_permissions_permission_fk` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_permissions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `password_changed_at` datetime DEFAULT NULL,
  `token_version` int unsigned NOT NULL DEFAULT '1',
  `role` enum('super_admin','admin','user','professeur') NOT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `subject_id` int DEFAULT NULL,
  `gender` enum('MALE','FEMALE') DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `specialty` varchar(120) DEFAULT NULL,
  `primary_school` varchar(150) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `school_id` (`school_id`),
  KEY `users_subject_id_fk` (`subject_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_subject_id_fk` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- Technical references only: global payment methods, permissions, and admin role grants.
INSERT INTO payment_methods (code,label,status) VALUES
('CASH','Cash','ACTIVE'),
('CARD','Card','ACTIVE'),
('BANK_TRANSFER','Bank transfer','ACTIVE'),
('CHECK','Check','ACTIVE'),
('MOBILE','Mobile payment','ACTIVE');

INSERT INTO permissions (code,label) VALUES
('students.manage','Manage students'),('students.import','Import students'),('families.manage','Manage families'),('student_finance.view','View student finance'),('payments.export','Export payments'),('expenses.view','View expenses'),('expenses.create','Create expenses'),('expenses.approve','Approve expenses'),('expenses.export','Export expenses'),('expense_categories.manage','Manage expense categories'),('personnel.view','View personnel'),('personnel.manage','Manage personnel'),('payments.view','View payments'),('payments.create','Create payments'),('payments.update','Update payments'),('payments.delete','Delete payments'),('monthly_fees.view','View monthly fees'),('monthly_fees.manage','Manage monthly fees'),('classes.view','View classes'),('classes.manage','Manage classes'),('schedules.view','View schedules'),('schedules.manage','Manage schedules'),('teachers.view','View teachers'),('teachers.manage','Manage teachers'),('charges.view','View charges'),('charges.manage','Manage charges'),('discounts.manage','Manage discounts'),('finance.view','View finance'),('finance.manage','Manage finance');

INSERT INTO role_permissions (role,permission_id) SELECT 'admin', id FROM permissions;

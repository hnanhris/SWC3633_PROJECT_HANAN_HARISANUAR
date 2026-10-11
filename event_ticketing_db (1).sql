-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 11, 2026 at 07:09 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `event_ticketing_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `booking_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `booking_status` enum('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  `booked_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`booking_id`, `user_id`, `schedule_id`, `quantity`, `total_price`, `booking_status`, `booked_at`) VALUES
(1, 4, 1, 2, 160.00, 'confirmed', '2026-10-09 17:20:52'),
(2, 5, 2, 1, 50.00, 'confirmed', '2026-10-09 17:20:52'),
(3, 6, 3, 2, 40.00, 'confirmed', '2026-10-09 17:20:52'),
(4, 4, 4, 1, 120.00, 'confirmed', '2026-10-09 17:20:52'),
(5, 5, 5, 3, 300.00, 'confirmed', '2026-10-09 17:20:52');

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `event_id` int(11) NOT NULL,
  `organiser_id` int(11) NOT NULL,
  `venue_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `status` enum('draft','published','cancelled') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`event_id`, `organiser_id`, `venue_id`, `title`, `description`, `category`, `status`, `created_at`) VALUES
(1, 2, 1, 'Technology Innovation Conference', 'Conference about emerging technologies', 'Technology', 'published', '2026-10-09 17:20:52'),
(2, 2, 2, 'Business Entrepreneurship Seminar', 'Seminar for aspiring entrepreneurs', 'Business', 'published', '2026-10-09 17:20:52'),
(3, 3, 3, 'Local Food Festival', 'Food and beverage showcase', 'Food', 'published', '2026-10-09 17:20:52'),
(4, 3, 4, 'Digital Photography Workshop', 'Practical photography workshop', 'Workshop', 'published', '2026-10-09 17:20:52'),
(5, 2, 5, 'Music and Culture Festival', 'Outdoor music and cultural event', 'Entertainment', 'published', '2026-10-09 17:20:52');

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `schedule_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `total_tickets` int(11) NOT NULL,
  `available_tickets` int(11) NOT NULL,
  `ticket_price` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedules`
--

INSERT INTO `schedules` (`schedule_id`, `event_id`, `start_time`, `end_time`, `total_tickets`, `available_tickets`, `ticket_price`) VALUES
(1, 1, '2026-11-15 09:00:00', '2026-11-15 17:00:00', 500, 498, 80.00),
(2, 2, '2026-11-20 10:00:00', '2026-11-20 15:00:00', 300, 299, 50.00),
(3, 3, '2026-11-25 11:00:00', '2026-11-25 20:00:00', 200, 198, 20.00),
(4, 4, '2026-12-01 09:00:00', '2026-12-01 13:00:00', 100, 99, 120.00),
(5, 5, '2026-12-05 16:00:00', '2026-12-05 23:00:00', 1000, 997, 100.00);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','organiser','customer') NOT NULL DEFAULT 'customer',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `email`, `password_hash`, `role`, `created_at`) VALUES
(1, 'System Administrator', 'admin@eventdemo.test', 'PASTE_FULL_GENERATED_HASH_HERE', 'admin', '2026-10-09 17:20:52'),
(2, 'Event Organiser One', 'organiser1@eventdemo.test', 'PENDING_HASH', 'organiser', '2026-10-09 17:20:52'),
(3, 'Event Organiser Two', 'organiser2@eventdemo.test', 'PENDING_HASH', 'organiser', '2026-10-09 17:20:52'),
(4, 'Customer One', 'customer1@eventdemo.test', 'PENDING_HASH', 'customer', '2026-10-09 17:20:52'),
(5, 'Customer Two', 'customer2@eventdemo.test', 'PENDING_HASH', 'customer', '2026-10-09 17:20:52'),
(6, 'Customer Three', 'customer3@eventdemo.test', 'PENDING_HASH', 'customer', '2026-10-09 17:20:52'),
(9, 'Aqeel Admin', 'aqeeladmin@eventdemo.test', '$2y$10$6Tn20zB7lZXWK2ZLkJ1UP.jd9wyEGOHwjS3zWUr9D7a4ISv6TwPOS', 'admin', '2026-10-11 16:46:19');

-- --------------------------------------------------------

--
-- Table structure for table `venues`
--

CREATE TABLE `venues` (
  `venue_id` int(11) NOT NULL,
  `venue_name` varchar(150) NOT NULL,
  `location` varchar(255) NOT NULL,
  `capacity` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `venues`
--

INSERT INTO `venues` (`venue_id`, `venue_name`, `location`, `capacity`, `description`, `created_at`) VALUES
(1, 'Grand Convention Hall', 'Kuala Lumpur', 500, 'Large indoor event hall', '2026-10-09 17:20:52'),
(2, 'City Auditorium', 'Shah Alam', 300, 'Auditorium for talks and seminars', '2026-10-09 17:20:52'),
(3, 'Community Event Centre', 'Petaling Jaya', 200, 'Multipurpose event space', '2026-10-09 17:20:52'),
(4, 'Creative Arts Studio', 'Subang Jaya', 100, 'Small creative workshop venue', '2026-10-09 17:20:52'),
(5, 'Outdoor Festival Ground', 'Putrajaya', 1000, 'Open-air event location', '2026-10-09 17:20:52');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`booking_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `schedule_id` (`schedule_id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`event_id`),
  ADD KEY `organiser_id` (`organiser_id`),
  ADD KEY `venue_id` (`venue_id`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `event_id` (`event_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `venues`
--
ALTER TABLE `venues`
  ADD PRIMARY KEY (`venue_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `booking_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `event_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `venues`
--
ALTER TABLE `venues`
  MODIFY `venue_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`schedule_id`);

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `events_ibfk_1` FOREIGN KEY (`organiser_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `events_ibfk_2` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`venue_id`);

--
-- Constraints for table `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

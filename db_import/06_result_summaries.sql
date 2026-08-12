SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
USE `hcispanel_kpn_tmp`;

INSERT INTO `result_summaries` (`id`, `employee_id`, `created_at`, `updated_at`, `critical_position`, `successor_type`, `successor_to_position`) VALUES
(8, '01124040020', '2026-01-07 03:29:36', '2026-01-07 03:29:36', 'Yes', 'SO (Ready Now)', 'PLT_DES7'),
(9, '01124080034', '2026-01-07 03:32:59', '2026-01-12 10:41:29', 'Yes', 'S1 (Ready 0-2 Years)', 'PLT_KWL2_DSG003'),
(10, '01123120040', '2026-01-07 03:33:56', '2026-01-07 03:33:56', 'Yes', 'SO (Ready Now)', 'CRP_HO_ERP_DES46');

SET FOREIGN_KEY_CHECKS=1;

-- ObraConnect 0.1.0 — SQL de instalação (esquema + dados base)
-- Gerado por: php artisan obraconnect:install-sql
--
-- Uso no cPanel sem SSH:
--   1. Crie a base de dados e o utilizador em "Bases de dados MySQL".
--   2. Abra o phpMyAdmin, escolha a base de dados vazia e use "Importar" com este ficheiro.
--   3. Configure o .env e abra /instalar para criar o super administrador.
-- Compatível com MySQL 8.0+ e MariaDB 10.6+ (utf8mb4).
-- Não contém dados pessoais nem dados de demonstração.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

create table `migrations` (`id` int unsigned not null auto_increment primary key, `migration` varchar(255) not null, `batch` int not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';

-- 0001_01_01_000000_create_users_table
create table `users` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(255) not null, `email` varchar(255) not null, `email_verified_at` timestamp null, `password` varchar(255) not null, `current_tenant_id` bigint unsigned null, `platform_role` varchar(20) null, `locale` varchar(10) not null default 'pt_PT', `is_active` tinyint(1) not null default '1', `last_login_at` timestamp null, `last_login_ip` varchar(45) null, `remember_token` varchar(100) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `users` add unique `users_email_unique`(`email`);
alter table `users` add index `users_current_tenant_id_index`(`current_tenant_id`);
alter table `users` add index `users_platform_role_index`(`platform_role`);
create table `password_reset_tokens` (`email` varchar(255) not null, `token` varchar(255) not null, `created_at` timestamp null, primary key (`email`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
create table `sessions` (`id` varchar(255) not null, `user_id` bigint unsigned null, `ip_address` varchar(45) null, `user_agent` text null, `payload` longtext not null, `last_activity` int not null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `sessions` add index `sessions_user_id_index`(`user_id`);
alter table `sessions` add index `sessions_last_activity_index`(`last_activity`);
insert into `migrations` (`migration`, `batch`) values ('0001_01_01_000000_create_users_table', 1);

-- 0001_01_01_000001_create_cache_table
create table `cache` (`key` varchar(255) not null, `value` mediumtext not null, `expiration` bigint not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `cache` add index `cache_expiration_index`(`expiration`);
create table `cache_locks` (`key` varchar(255) not null, `owner` varchar(255) not null, `expiration` bigint not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `cache_locks` add index `cache_locks_expiration_index`(`expiration`);
insert into `migrations` (`migration`, `batch`) values ('0001_01_01_000001_create_cache_table', 1);

-- 0001_01_01_000002_create_jobs_table
create table `jobs` (`id` bigint unsigned not null auto_increment primary key, `queue` varchar(255) not null, `payload` longtext not null, `attempts` smallint unsigned not null, `reserved_at` int unsigned null, `available_at` int unsigned not null, `created_at` int unsigned not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `jobs` add index `jobs_queue_index`(`queue`);
create table `job_batches` (`id` varchar(255) not null, `name` varchar(255) not null, `total_jobs` int not null, `pending_jobs` int not null, `failed_jobs` int not null, `failed_job_ids` longtext not null, `options` mediumtext null, `cancelled_at` int null, `created_at` int not null, `finished_at` int null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
create table `failed_jobs` (`id` bigint unsigned not null auto_increment primary key, `uuid` varchar(255) not null, `connection` varchar(255) not null, `queue` varchar(255) not null, `payload` longtext not null, `exception` longtext not null, `failed_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `failed_jobs` add index `failed_jobs_connection_queue_failed_at_index`(`connection`, `queue`, `failed_at`);
alter table `failed_jobs` add unique `failed_jobs_uuid_unique`(`uuid`);
insert into `migrations` (`migration`, `batch`) values ('0001_01_01_000002_create_jobs_table', 1);

-- 0001_01_01_000003_add_two_factor_columns_to_users_table
alter table `users` add `two_factor_secret` text null after `password`;
alter table `users` add `two_factor_recovery_codes` text null after `two_factor_secret`;
alter table `users` add `two_factor_confirmed_at` timestamp null after `two_factor_recovery_codes`;
insert into `migrations` (`migration`, `batch`) values ('0001_01_01_000003_add_two_factor_columns_to_users_table', 1);

-- 0001_01_01_000004_create_personal_access_tokens_table
create table `personal_access_tokens` (`id` bigint unsigned not null auto_increment primary key, `tokenable_type` varchar(255) not null, `tokenable_id` bigint unsigned not null, `name` text not null, `token` varchar(64) not null, `abilities` text null, `last_used_at` timestamp null, `expires_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `personal_access_tokens` add index `personal_access_tokens_tokenable_type_tokenable_id_index`(`tokenable_type`, `tokenable_id`);
alter table `personal_access_tokens` add unique `personal_access_tokens_token_unique`(`token`);
alter table `personal_access_tokens` add index `personal_access_tokens_expires_at_index`(`expires_at`);
insert into `migrations` (`migration`, `batch`) values ('0001_01_01_000004_create_personal_access_tokens_table', 1);

-- 2026_10_28_100000_create_plans_table
create table `plans` (`id` bigint unsigned not null auto_increment primary key, `code` varchar(40) not null, `name` varchar(100) not null, `description` text null, `price_monthly_cents` int unsigned null, `price_yearly_cents` int unsigned null, `currency` char(3) not null default 'EUR', `trial_days` smallint unsigned not null default '0', `limits` json not null, `features` json not null, `is_public` tinyint(1) not null default '1', `is_active` tinyint(1) not null default '1', `sort_order` smallint unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `plans` add unique `plans_code_unique`(`code`);
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100000_create_plans_table', 1);

-- 2026_10_28_100100_create_tenants_table
create table `tenants` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(150) not null, `legal_name` varchar(200) null, `nif` varchar(20) null, `email` varchar(255) null, `phone` varchar(30) null, `website` varchar(255) null, `address_line1` varchar(255) null, `address_line2` varchar(255) null, `postal_code` varchar(20) null, `city` varchar(100) null, `country_code` char(2) not null default 'PT', `logo_path` varchar(255) null, `status` varchar(20) not null default 'active', `status_reason` varchar(255) null, `status_changed_at` timestamp null, `settings` json null, `storage_used_bytes` bigint unsigned not null default '0', `database_connection` varchar(64) null, `created_by_user_id` bigint unsigned null, `deletion_requested_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `tenants` add constraint `tenants_created_by_user_id_foreign` foreign key (`created_by_user_id`) references `users` (`id`) on delete set null;
alter table `tenants` add index `tenants_nif_index`(`nif`);
alter table `tenants` add index `tenants_status_index`(`status`);
alter table `users` add constraint `users_current_tenant_id_foreign` foreign key (`current_tenant_id`) references `tenants` (`id`) on delete set null;
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100100_create_tenants_table', 1);

-- 2026_10_28_100200_create_subscriptions_table
create table `subscriptions` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `plan_id` bigint unsigned not null, `status` varchar(20) not null, `billing_cycle` varchar(10) not null default 'monthly', `trial_ends_at` timestamp null, `current_period_starts_at` timestamp null, `current_period_ends_at` timestamp null, `canceled_at` timestamp null, `ends_at` timestamp null, `custom_limits` json null, `provider` varchar(30) not null default 'manual', `provider_reference` varchar(255) null, `notes` text null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `subscriptions` add constraint `subscriptions_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `subscriptions` add constraint `subscriptions_plan_id_foreign` foreign key (`plan_id`) references `plans` (`id`) on delete restrict;
alter table `subscriptions` add index `subscriptions_tenant_id_status_index`(`tenant_id`, `status`);
alter table `subscriptions` add index `subscriptions_status_trial_ends_at_index`(`status`, `trial_ends_at`);
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100200_create_subscriptions_table', 1);

-- 2026_10_28_100300_create_access_control_tables
create table `permissions` (`id` bigint unsigned not null auto_increment primary key, `key` varchar(100) not null, `module` varchar(50) not null, `description` varchar(255) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `permissions` add unique `permissions_key_unique`(`key`);
alter table `permissions` add index `permissions_module_index`(`module`);
create table `roles` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `key` varchar(60) not null, `name` varchar(100) not null, `description` varchar(255) null, `template_key` varchar(60) null, `is_locked` tinyint(1) not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `roles` add constraint `roles_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `roles` add unique `roles_tenant_id_key_unique`(`tenant_id`, `key`);
alter table `roles` add unique `roles_tenant_id_id_unique`(`tenant_id`, `id`);
create table `permission_role` (`role_id` bigint unsigned not null, `permission_id` bigint unsigned not null, primary key (`role_id`, `permission_id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `permission_role` add constraint `permission_role_role_id_foreign` foreign key (`role_id`) references `roles` (`id`) on delete cascade;
alter table `permission_role` add constraint `permission_role_permission_id_foreign` foreign key (`permission_id`) references `permissions` (`id`) on delete cascade;
create table `memberships` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `user_id` bigint unsigned not null, `role_id` bigint unsigned not null, `status` varchar(20) not null default 'active', `job_title` varchar(100) null, `invited_by_user_id` bigint unsigned null, `joined_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `memberships` add constraint `memberships_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `memberships` add constraint `memberships_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;
alter table `memberships` add constraint `memberships_invited_by_user_id_foreign` foreign key (`invited_by_user_id`) references `users` (`id`) on delete set null;
alter table `memberships` add unique `memberships_tenant_id_user_id_unique`(`tenant_id`, `user_id`);
alter table `memberships` add index `memberships_user_id_status_index`(`user_id`, `status`);
alter table `memberships` add constraint `memberships_tenant_id_role_id_foreign` foreign key (`tenant_id`, `role_id`) references `roles` (`tenant_id`, `id`) on delete cascade;
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100300_create_access_control_tables', 1);

-- 2026_10_28_100400_create_invitations_table
create table `invitations` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `email` varchar(255) not null, `role_id` bigint unsigned not null, `token_hash` char(64) not null, `invited_by_user_id` bigint unsigned null, `expires_at` timestamp not null, `accepted_at` timestamp null, `accepted_by_user_id` bigint unsigned null, `revoked_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `invitations` add constraint `invitations_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `invitations` add constraint `invitations_invited_by_user_id_foreign` foreign key (`invited_by_user_id`) references `users` (`id`) on delete set null;
alter table `invitations` add constraint `invitations_accepted_by_user_id_foreign` foreign key (`accepted_by_user_id`) references `users` (`id`) on delete set null;
alter table `invitations` add index `invitations_tenant_id_email_index`(`tenant_id`, `email`);
alter table `invitations` add constraint `invitations_tenant_id_role_id_foreign` foreign key (`tenant_id`, `role_id`) references `roles` (`tenant_id`, `id`) on delete cascade;
alter table `invitations` add unique `invitations_token_hash_unique`(`token_hash`);
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100400_create_invitations_table', 1);

-- 2026_10_28_100500_create_audit_logs_table
create table `audit_logs` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned null, `actor_user_id` bigint unsigned null, `actor_type` varchar(20) not null, `event` varchar(80) not null, `subject_type` varchar(80) null, `subject_id` bigint unsigned null, `properties` json null, `ip_address` varchar(45) null, `user_agent` varchar(255) null, `request_id` varchar(36) null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `audit_logs` add index `audit_logs_tenant_id_created_at_index`(`tenant_id`, `created_at`);
alter table `audit_logs` add index `audit_logs_subject_type_subject_id_index`(`subject_type`, `subject_id`);
alter table `audit_logs` add index `audit_logs_actor_user_id_index`(`actor_user_id`);
alter table `audit_logs` add index `audit_logs_event_index`(`event`);
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100500_create_audit_logs_table', 1);

-- 2026_10_28_100600_create_support_access_grants_table
create table `support_access_grants` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `granted_by_user_id` bigint unsigned null, `reason` varchar(500) not null, `access_level` varchar(20) not null default 'read', `expires_at` timestamp not null, `revoked_at` timestamp null, `revoked_by_user_id` bigint unsigned null, `last_used_at` timestamp null, `last_used_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `support_access_grants` add constraint `support_access_grants_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `support_access_grants` add constraint `support_access_grants_granted_by_user_id_foreign` foreign key (`granted_by_user_id`) references `users` (`id`) on delete set null;
alter table `support_access_grants` add constraint `support_access_grants_revoked_by_user_id_foreign` foreign key (`revoked_by_user_id`) references `users` (`id`) on delete set null;
alter table `support_access_grants` add constraint `support_access_grants_last_used_by_user_id_foreign` foreign key (`last_used_by_user_id`) references `users` (`id`) on delete set null;
alter table `support_access_grants` add index `support_access_grants_tenant_id_expires_at_index`(`tenant_id`, `expires_at`);
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100600_create_support_access_grants_table', 1);

-- 2026_10_28_100700_create_tenant_exports_table
create table `tenant_exports` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `requested_by_user_id` bigint unsigned null, `status` varchar(20) not null default 'pending', `disk` varchar(30) not null, `path` varchar(255) null, `size_bytes` bigint unsigned null, `checksum_sha256` char(64) null, `error` text null, `completed_at` timestamp null, `expires_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `tenant_exports` add constraint `tenant_exports_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `tenant_exports` add constraint `tenant_exports_requested_by_user_id_foreign` foreign key (`requested_by_user_id`) references `users` (`id`) on delete set null;
alter table `tenant_exports` add index `tenant_exports_tenant_id_created_at_index`(`tenant_id`, `created_at`);
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100700_create_tenant_exports_table', 1);

-- Dados base
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('company.view', 'company', 'Ver dados da empresa', '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('company.update', 'company', 'Editar dados e logótipo da empresa', '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('security.manage', 'company', 'Definir políticas de segurança (ex.: 2FA obrigatório)', '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('members.view', 'members', 'Ver utilizadores e convites', '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('members.invite', 'members', 'Convidar utilizadores', '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('members.manage', 'members', 'Alterar perfil, suspender e remover utilizadores', '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('roles.view', 'roles', 'Ver perfis e permissões', '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('roles.manage', 'roles', 'Criar e editar perfis', '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('audit.view', 'audit', 'Consultar o registo de auditoria', '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('subscription.view', 'subscription', 'Ver plano, limites e utilização', '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('support.manage', 'support', 'Autorizar e revogar acesso do suporte técnico', '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('data.export', 'data', 'Exportar todos os dados da empresa', '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `plans` (`code`, `name`, `description`, `price_monthly_cents`, `price_yearly_cents`, `trial_days`, `limits`, `features`, `sort_order`, `currency`, `is_public`, `is_active`, `updated_at`, `created_at`) values ('start', 'Start', 'Para pequenas empresas: até 3 obras ativas e funcionalidades essenciais.', 4900, 49000, 30, '{\"max_users\":5,\"max_active_projects\":3,\"storage_mb\":5120}', '[\"diary\",\"documents\"]', 10, 'EUR', 1, 1, '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `plans` (`code`, `name`, `description`, `price_monthly_cents`, `price_yearly_cents`, `trial_days`, `limits`, `features`, `sort_order`, `currency`, `is_public`, `is_active`, `updated_at`, `created_at`) values ('professional', 'Professional', 'Até 15 obras ativas, fiscalização, controlo de custos e relatórios avançados.', 12900, 129000, 30, '{\"max_users\":25,\"max_active_projects\":15,\"storage_mb\":51200}', '[\"diary\",\"documents\",\"inspections\",\"costs\",\"advanced_reports\"]', 20, 'EUR', 1, 1, '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `plans` (`code`, `name`, `description`, `price_monthly_cents`, `price_yearly_cents`, `trial_days`, `limits`, `features`, `sort_order`, `currency`, `is_public`, `is_active`, `updated_at`, `created_at`) values ('business', 'Business', 'Mais obras, inteligência artificial, integrações e automações.', 29900, 299000, 30, '{\"max_users\":100,\"max_active_projects\":60,\"storage_mb\":204800}', '[\"diary\",\"documents\",\"inspections\",\"costs\",\"advanced_reports\",\"ai\",\"integrations\",\"automations\"]', 30, 'EUR', 1, 1, '2026-10-28 01:58:46', '2026-10-28 01:58:46');
insert into `plans` (`code`, `name`, `description`, `price_monthly_cents`, `price_yearly_cents`, `trial_days`, `limits`, `features`, `sort_order`, `currency`, `is_public`, `is_active`, `updated_at`, `created_at`) values ('enterprise', 'Enterprise', 'Preço personalizado, integrações dedicadas, suporte prioritário e requisitos específicos de segurança.', null, null, 0, '{\"max_users\":null,\"max_active_projects\":null,\"storage_mb\":null}', '[\"diary\",\"documents\",\"inspections\",\"costs\",\"advanced_reports\",\"ai\",\"integrations\",\"automations\",\"dedicated_database\"]', 40, 'EUR', 1, 1, '2026-10-28 01:58:46', '2026-10-28 01:58:46');

SET FOREIGN_KEY_CHECKS = 1;

-- Review queues: per-program status counts and per-program paging.
ALTER TABLE `applications`
  ADD KEY `idx_app_form_status_created` (`form_id`, `status`, `created_at`);

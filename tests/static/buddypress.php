<?php
// Static declarations match the signatures verified against BuddyPress 14.5.2 source.
function buddypress(): stdClass { return new stdClass(); }
function bp_get_root_blog_id(): int { return 1; }
function bp_is_active(string $component): bool { return false; }
function bp_members_get_user_url(int $user_id=0,array $path_chunks=[]): string { return ''; }
function bp_core_get_users(array $args=[]): array { return []; }
function groups_get_group(int $group_id): object { return new stdClass(); }
function groups_get_groups(array $args=[]): array { return []; }
function groups_is_user_member(int $user_id,int $group_id): bool { return false; }
function groups_is_user_banned(int $user_id,int $group_id): bool { return false; }
function groups_get_groupmeta(int $group_id,string $meta_key='',bool $single=true) { return null; }
function groups_update_groupmeta(int $group_id,string $meta_key,$value): bool { return true; }
function bp_get_group_url($group=0,array $path_chunks=[]): string { return ''; }
function bp_activity_get(array $args=[]): array { return []; }
function bp_activity_get_specific(array $args=[]): array { return []; }
function bp_activity_get_meta(int $activity_id,string $key='',bool $single=true) { return null; }
function bp_activity_update_meta(int $activity_id,string $key,$value): bool { return true; }
function bp_activity_get_permalink(int $activity_id,$object=false): string { return ''; }
function friends_check_friendship(int $user_id,int $possible_friend_id): bool { return false; }
function bp_get_member_type(int $user_id,bool $single=true) { return false; }
function bp_get_member_types(array $args=[]): array { return []; }
function bp_notifications_add_notification(array $args=[]) { return false; }
function bp_core_new_nav_item(array $args,string $component='members') { return null; }
function bp_core_load_template(array $templates): void {}
function bp_is_my_profile(): bool { return false; }
function bp_displayed_user_id(): int { return 0; }
function bp_get_current_group_id(): int { return 0; }
function bp_get_activity_id(): int { return 0; }
function bp_get_version(): string { return ''; }
function bp_is_current_component(string $component): bool { return false; }
function bp_current_user_can(string $capability,array $args=[]): bool { return false; }
function bp_user_can(int $user_id,string $capability,array $args=[]): bool { return false; }

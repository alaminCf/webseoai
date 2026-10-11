<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with Techboloy agency foundations.
     */
    public function run(): void
    {
        // 1. Primary Owner User
        $owner = User::firstOrCreate(
            ['email' => 'admin@techboloy.com'],
            [
                'name' => 'Techboloy Lead',
                'password' => Hash::make('Admin1234!'),
            ]
        );

        // 2. SEO Specialist User
        $specialist = User::firstOrCreate(
            ['email' => 'specialist@techboloy.com'],
            [
                'name' => 'SEO Specialist',
                'password' => Hash::make('Specialist1234!'),
            ]
        );

        // 3. Client Viewer User
        $viewer = User::firstOrCreate(
            ['email' => 'client@carematebd.com'],
            [
                'name' => 'CareMate Viewer',
                'password' => Hash::make('Client1234!'),
            ]
        );

        // 4. Primary Agency Organization
        $agencyOrg = Organization::firstOrCreate(
            ['slug' => 'techboloy-agency'],
            [
                'name' => 'Techboloy Agency',
                'billing_plan' => 'agency_internal',
            ]
        );

        // Memberships
        OrganizationMember::firstOrCreate(
            ['organization_id' => $agencyOrg->id, 'user_id' => $owner->id],
            ['role' => OrganizationMember::ROLE_OWNER]
        );

        OrganizationMember::firstOrCreate(
            ['organization_id' => $agencyOrg->id, 'user_id' => $specialist->id],
            ['role' => OrganizationMember::ROLE_SEO_MANAGER]
        );

        // 5. Client Organization (for testing multi-tenant switching)
        $clientOrg = Organization::firstOrCreate(
            ['slug' => 'caremate-bd'],
            [
                'name' => 'CareMate BD Workspace',
                'billing_plan' => 'starter',
            ]
        );

        OrganizationMember::firstOrCreate(
            ['organization_id' => $clientOrg->id, 'user_id' => $owner->id],
            ['role' => OrganizationMember::ROLE_OWNER]
        );

        OrganizationMember::firstOrCreate(
            ['organization_id' => $clientOrg->id, 'user_id' => $viewer->id],
            ['role' => OrganizationMember::ROLE_VIEWER]
        );

        // 6. Sample Websites in Techboloy Agency
        $wpSite = Website::firstOrCreate(
            ['organization_id' => $agencyOrg->id, 'domain' => 'techboloy.com'],
            [
                'name' => 'Techboloy Corporate',
                'target_url' => 'https://techboloy.com',
                'platform' => Website::PLATFORM_WORDPRESS,
                'timezone' => 'Asia/Dhaka',
                'preferred_language' => 'en',
                'target_country' => 'BD',
                'connection_status' => Website::STATUS_DISCONNECTED,
                'verification_status' => Website::VERIFICATION_VERIFIED,
                'verified_at' => now(),
                'health_score' => 88,
                'last_crawled_at' => now()->subDays(2),
                'last_audited_at' => now()->subDays(2),
            ]
        );

        $shopifySite = Website::firstOrCreate(
            ['organization_id' => $agencyOrg->id, 'domain' => 'store.techboloy.com'],
            [
                'name' => 'Techboloy Merch Store',
                'target_url' => 'https://store.techboloy.com',
                'platform' => Website::PLATFORM_SHOPIFY,
                'timezone' => 'America/New_York',
                'preferred_language' => 'en',
                'target_country' => 'US',
                'connection_status' => Website::STATUS_DISCONNECTED,
                'verification_status' => Website::VERIFICATION_UNVERIFIED,
                'health_score' => 74,
                'last_crawled_at' => now()->subDays(5),
            ]
        );

        // Audit Log Entry
        AuditLog::record(
            $agencyOrg->id,
            $owner->id,
            'seeder.initialized',
            $agencyOrg,
            null,
            ['websites' => 2, 'members' => 2]
        );
    }
}

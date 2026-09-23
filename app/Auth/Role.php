<?php

namespace App\Auth;

enum Role: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Agent = 'agent';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => Permission::cases(),
            self::Admin => [
                Permission::AdminAccess,
                Permission::HelpdeskAccess,
                Permission::UsersManage,
                Permission::ClientsView,
                Permission::ClientsManage,
                Permission::ClientsPinSet,
                Permission::ClientsPackageOverride,
                Permission::ClientsExport,
                Permission::ExternalRecordsView,
                Permission::SyncManage,
                Permission::QuestionsManage,
                Permission::SettingsManage,
                Permission::RolesManage,
                Permission::NewsManage,
                Permission::AuthProvidersManage,
                Permission::ApiKeysManage,
                Permission::AuditView,
                Permission::CallsView,
                Permission::IdentificationRun,
                Permission::IdentificationView,
                Permission::IdentificationPinVerify,
                Permission::IdentificationManual,
                Permission::ReportsExport,
            ],
            self::Supervisor => [
                Permission::HelpdeskAccess,
                Permission::ClientsView,
                Permission::ClientsPinSet,
                Permission::CallsView,
                Permission::IdentificationRun,
                Permission::IdentificationView,
                Permission::IdentificationPinVerify,
                Permission::IdentificationManual,
                Permission::AuditView,
                Permission::ReportsExport,
            ],
            self::Agent => [
                Permission::HelpdeskAccess,
                Permission::ClientsView,
                Permission::ClientsPinSet,
                Permission::CallsView,
                Permission::IdentificationRun,
                Permission::IdentificationPinVerify,
                Permission::IdentificationManual,
            ],
        };
    }
}

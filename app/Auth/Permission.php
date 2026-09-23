<?php

namespace App\Auth;

/**
 * Every permission the application checks. Seeded by RolesAndPermissionsSeeder.
 */
enum Permission: string
{
    case AdminAccess = 'admin.access';
    case HelpdeskAccess = 'helpdesk.access';

    case UsersManage = 'users.manage';
    case ClientsView = 'clients.view';
    case ClientsManage = 'clients.manage';
    case ClientsPinSet = 'clients.pin.set';
    case ClientsPackageOverride = 'clients.package.override';
    case ClientsExport = 'clients.export';
    case ExternalRecordsView = 'external-records.view';
    case SyncManage = 'sync.manage';
    case QuestionsManage = 'questions.manage';
    case SettingsManage = 'settings.manage';
    case RolesManage = 'roles.manage';
    case NewsManage = 'news.manage';
    case AuthProvidersManage = 'auth-providers.manage';
    case ApiKeysManage = 'api-keys.manage';
    case AuditView = 'audit.view';
    case CallsView = 'calls.view';
    case IdentificationRun = 'identification.run';
    case IdentificationView = 'identification.view';
    case IdentificationPinVerify = 'identification.pin.verify';
    case IdentificationManual = 'identification.manual';
    case ReportsExport = 'reports.export';

    public function label(): string
    {
        return __('permission.'.$this->value);
    }

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }
}

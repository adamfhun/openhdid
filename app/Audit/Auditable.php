<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Records create / update / delete / restore of a model to the audit log.
 * Hidden attributes are never written. Add `$auditExclude` on the model to
 * skip further attributes (e.g. counters that change constantly).
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->audit('created', ['attributes' => $model->auditableAttributes($model->getAttributes())]));
        static::updated(function (Model $model): void {
            $changes = $model->auditableAttributes($model->getChanges());
            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $original = array_intersect_key($model->auditableAttributes($model->getOriginal()), $changes);
            $model->audit('updated', ['from' => $original, 'to' => $changes]);
        });
        static::deleted(fn (Model $model) => $model->audit(method_exists($model, 'isForceDeleting') && $model->isForceDeleting() ? 'force_deleted' : 'deleted'));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => $model->audit('restored'));
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function audit(string $action, array $context = []): void
    {
        app(Auditor::class)->record(
            $this->auditEventPrefix().'.'.$action,
            $this,
            $context,
        );
    }

    protected function auditEventPrefix(): string
    {
        return str(class_basename($this))->snake()->toString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditableAttributes(array $attributes): array
    {
        $exclude = array_merge($this->getHidden(), property_exists($this, 'auditExclude') ? $this->auditExclude : []);

        return array_diff_key($attributes, array_flip($exclude));
    }
}

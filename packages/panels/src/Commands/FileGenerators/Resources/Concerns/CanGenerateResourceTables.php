<?php

namespace Filament\Commands\FileGenerators\Resources\Concerns;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Support\Str;
use Nette\PhpGenerator\Literal;

trait CanGenerateResourceTables
{
    public function generateTableMethodBody(): string
    {
        $this->importUnlessPartial(BulkActionGroup::class);

        return <<<PHP
            return \$table
                ->columns([
                    {$this->outputTableColumns()}
                ])
                ->filters([
                    {$this->outputTableFilters()}
                ])
                ->actions([
                    {$this->outputTableActions()}
                ])
                ->bulkActions([
                    {$this->simplifyFqn(BulkActionGroup::class)}::make([
                        {$this->outputTableMethodBulkActions()}
                    ]),
                ]);
            PHP;
    }

    /**
     * @return array<string>
     */
    public function getTableColumns(): array
    {
        if (! $this->isGenerated()) {
            return [];
        }

        $model = $this->getModelFqn();

        if (! class_exists($model)) {
            return [];
        }

        $schema = $this->getModelSchema($model);
        $table = $this->getModelTable($model);

        $columns = [];

        foreach ($schema->getColumns($table) as $column) {
            if ($column['auto_increment']) {
                continue;
            }

            $type = $this->parseColumnType($column);

            if (in_array($type['name'], [
                'json',
                'text',
            ])) {
                continue;
            }

            $columnName = $column['name'];

            if (str($columnName)->endsWith([
                '_token',
            ])) {
                continue;
            }

            if (str($columnName)->contains([
                'password',
            ])) {
                continue;
            }

            if (str($columnName)->endsWith('_id')) {
                $guessedRelationshipName = $this->guessBelongsToRelationshipName($columnName, $model);

                if (filled($guessedRelationshipName)) {
                    $guessedRelationshipTitleColumnName = $this->guessBelongsToRelationshipTitleColumnName($columnName, app($model)->{$guessedRelationshipName}()->getModel()::class);

                    $columnName = "{$guessedRelationshipName}.{$guessedRelationshipTitleColumnName}";
                }
            }

            $columnData = [];

            if (in_array($columnName, [
                'id',
                'sku',
                'uuid',
            ])) {
                $columnData['label'] = [Str::upper($columnName)];
            }

            if ($type['name'] === 'boolean') {
                $columnData['type'] = IconColumn::class;
                $columnData['boolean'] = [];
            } else {
                $columnData['type'] = match (true) {
                    $columnName === 'image', str($columnName)->startsWith('image_'), str($columnName)->contains('_image_'), str($columnName)->endsWith('_image') => ImageColumn::class,
                    default => TextColumn::class,
                };

                if (in_array($type['name'], [
                    'string',
                    'char',
                ]) && ($columnData['type'] === TextColumn::class)) {
                    $columnData['searchable'] = [];
                }

                if (in_array($type['name'], [
                    'date',
                ])) {
                    $columnData['date'] = [];
                    $columnData['sortable'] = [];
                }

                if (in_array($type['name'], [
                    'datetime',
                    'timestamp',
                ])) {
                    $columnData['dateTime'] = [];
                    $columnData['sortable'] = [];
                }

                if (in_array($type['name'], [
                    'integer',
                    'decimal',
                    'float',
                    'double',
                    'money',
                ])) {
                    $columnData[in_array($columnName, [
                        'cost',
                        'money',
                        'price',
                    ]) || $type['name'] === 'money' ? 'money' : 'numeric'] = [];
                    $columnData['sortable'] = [];
                }
            }

            if (in_array($columnName, [
                'created_at',
                'updated_at',
                'deleted_at',
            ])) {
                $columnData['toggleable'] = ['isToggledHiddenByDefault' => true];
            }

            $this->importUnlessPartial($columnData['type']);

            $columns[$columnName] = $columnData;
        }

        return array_map(
            function (array $columnData, string $columnName): string {
                $column = (string) new Literal("{$this->simplifyFqn($columnData['type'])}::make(?)", [$columnName]);

                unset($columnData['type']);

                foreach ($columnData as $methodName => $parameters) {
                    $column .= new Literal(PHP_EOL . "            ->{$methodName}(...?:)", [$parameters]);
                }

                return "{$column},";
            },
            $columns,
            array_keys($columns),
        );
    }

    public function outputTableColumns(): string
    {
        $columns = $this->getTableColumns();

        if (empty($columns)) {
            return '//';
        }

        return implode(PHP_EOL . '        ', $columns);
    }

    /**
     * @return array<class-string<Filter>>
     */
    public function getTableFilters(): array
    {
        $filters = [];

        if ($this->isSoftDeletable()) {
            $filters[] = TrashedFilter::class;
        }

        foreach ($filters as $filter) {
            $this->importUnlessPartial($filter);
        }

        return $filters;
    }

    public function outputTableFilters(): string
    {
        $filters = $this->getTableFilters();

        if (empty($filters)) {
            return '//';
        }

        return implode(PHP_EOL . '        ', array_map(
            fn (string $filter) => "{$this->simplifyFqn($filter)}::make(),",
            $filters,
        ));
    }

    /**
     * @return array<class-string<Action>>
     */
    public function getTableActions(): array
    {
        $actions = [];

        if ($this->hasViewOperation()) {
            $actions[] = ViewAction::class;
        }

        $actions[] = EditAction::class;

        if ($this->isSimple()) {
            $actions[] = DeleteAction::class;

            if ($this->isSoftDeletable()) {
                $actions[] = ForceDeleteAction::class;
                $actions[] = RestoreAction::class;
            }
        }

        foreach ($actions as $action) {
            $this->importUnlessPartial($action);
        }

        return $actions;
    }

    public function outputTableActions(): string
    {
        return implode(PHP_EOL . '        ', array_map(
            fn (string $action) => "{$this->simplifyFqn($action)}::make(),",
            $this->getTableActions(),
        ));
    }

    /**
     * @return array<class-string<Action>>
     */
    public function getTableBulkActions(): array
    {
        $actions = [
            DeleteBulkAction::class,
        ];

        if ($this->isSoftDeletable()) {
            $actions[] = ForceDeleteBulkAction::class;
            $actions[] = RestoreBulkAction::class;
        }

        foreach ($actions as $action) {
            $this->importUnlessPartial($action);
        }

        return $actions;
    }

    public function outputTableMethodBulkActions(): string
    {
        return implode(PHP_EOL . '            ', array_map(
            fn (string $action) => "{$this->simplifyFqn($action)}::make(),",
            $this->getTableBulkActions(),
        ));
    }
}
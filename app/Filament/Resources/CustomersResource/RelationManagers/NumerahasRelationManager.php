<?php

namespace App\Filament\Resources\CustomersResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

use Filament\Forms\Components\Card;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Tables\Filters\Filter;
use Illuminate\Support\HtmlString;
use App\Models\Customers;

class NumerahasRelationManager extends RelationManager
{
    protected static string $relationship = 'numerahas';
    protected static ?string $title = 'د پاسنی مشتری ټولی مربوطه ځمکی';


    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('numera_id')
                    ->label('د نمری آی ډی')
                    ->required()
                    ->maxLength(191),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('numera_id')
            ->columns([
                Tables\Columns\TextColumn::make('numera_id')
                    ->sortable()
                    ->label('د نمری آی ډی')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('numera_type')
                    ->sortable()
                    ->label('تفصیل')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('date')
                    ->sortable()
                    ->date()
                    ->label('نیټه')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('sharwali_tarifa_price')
                    ->sortable()
                    ->label('د ښاروالی د تعرفی پیسی')
                    ->toggleable()
                    ->searchable(),
                Tables\Columns\ImageColumn::make('Customer_image')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('د مشتری عکس')
                ,
                Tables\Columns\TextColumn::make('documents')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('اسناد')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->label('د ثبت نیټه')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->date()
                    ->label('د بدلون نیټه')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('due_price_range')
                    ->label('قرزداران')
                    ->form([
                        Forms\Components\TextInput::make('min_due_price')
                            ->label('کمترین قرز')
                            ->numeric(),

                        Forms\Components\TextInput::make('max_due_price')
                            ->label('جګ ترین قرز')
                            ->numeric(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        // Ensure min and max values are set
                        $min = $data['min_due_price'] ?? null;
                        $max = $data['max_due_price'] ?? null;

                        // Filter logic
                        return $query->when($min !== null, fn($query) => $this->whereAmountDue($query, '>=', $min))
                            ->when($max !== null, fn($query) => $this->whereAmountDue($query, '<=', $max));
                    })
                    ->indicateUsing(function (array $data): array {
                        // Display current filter range
                        $indicators = [];

                        if ($data['min_due_price'] ?? null) {
                            $indicators['min_due_price'] = 'Min Due Price: $' . $data['min_due_price'];
                        }

                        if ($data['max_due_price'] ?? null) {
                            $indicators['max_due_price'] = 'Max Due Price: $' . $data['max_due_price'];
                        }

                        return $indicators;
                    }),
                Filter::make('ټول قرزداران')
                    ->query(fn(Builder $query) => $this->whereAmountDue($query, '>', 0)),
            ])
            ->headerActions([
                // Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    private function whereAmountDue(Builder $query, string $operator, $amount): Builder
    {
        $operator = in_array($operator, ['>=', '<=', '>'], true) ? $operator : '>=';
        $customerId = $this->getOwnerRecord()->getKey();

        return $query->whereExists(function ($sub) use ($operator, $amount, $customerId) {
            $sub->selectRaw('1')
                ->from('customer_numerahas')
                ->whereColumn('customer_numerahas.numeraha_id', 'numerahas.id')
                ->where('customer_numerahas.customer_id', $customerId)
                ->whereRaw(
                    "CAST(customer_numerahas.total_price AS DECIMAL(15,2)) - CAST(customer_numerahas.payed_price AS DECIMAL(15,2)) {$operator} ?",
                    [$amount]
                );
        });
    }
}

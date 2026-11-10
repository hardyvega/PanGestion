<section class="cash-action-panel" aria-labelledby="manual-movement-title">
    <h3 id="manual-movement-title">Registrar movimiento manual</h3>
    @php
        $movementTypes = [
            'ingreso' => ['label' => 'Ingreso — Entrada adicional de efectivo', 'help' => 'Dinero adicional que entra a caja. No utilizar para registrar ventas.'],
            'egreso' => ['label' => 'Egreso — Pago de gasto operativo', 'help' => 'Dinero que sale para pagar un gasto operativo de la panadería.'],
            'retiro' => ['label' => 'Retiro — Extracción de efectivo', 'help' => 'Efectivo extraído de caja que no corresponde a un gasto operativo.'],
        ];
        $selectedType = old('tipo_movimiento', 'ingreso');
        $selectedType = is_string($selectedType) && isset($movementTypes[$selectedType]) ? $selectedType : 'ingreso';
    @endphp
    <form method="POST" action="{{ route('cash.movements.store', $session) }}" data-submit-once data-cash-manual-movement>
        @csrf
        <label class="field-group">
            <span>Tipo</span>
            <select class="form-input" name="tipo_movimiento" aria-describedby="manual-movement-help-{{ $session->getKey() }}" data-cash-movement-type required>
                @foreach ($movementTypes as $value => $type)
                    <option value="{{ $value }}" data-description="{{ $type['help'] }}" @selected($selectedType === $value)>{{ $type['label'] }}</option>
                @endforeach
            </select>
        </label>
        <p class="field-help" id="manual-movement-help-{{ $session->getKey() }}" data-cash-movement-help role="status" aria-live="polite">{{ $movementTypes[$selectedType]['help'] }}</p>
        <label class="field-group"><span>Monto</span><input class="form-input" name="monto" inputmode="decimal" required></label>
        <label class="field-group"><span>Concepto</span><textarea class="form-input" name="concepto" rows="3" required></textarea></label>
        <button class="primary-button" type="submit">Registrar movimiento</button>
    </form>
</section>

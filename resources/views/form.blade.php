{{--
    The ready-made ticket form: {{ tickets:form }}.
    Publish it to change the markup: php artisan vendor:publish --tag=statamic-tickets-views
    Colours follow the site: set --kt-accent (and --kt-accent-text) on any parent to brand it.
--}}
<div class="kt" data-kt-form>
<style>
.kt{--kt-accent-fallback:#2563eb;--kt-line:color-mix(in srgb,currentColor 18%,transparent);--kt-soft:color-mix(in srgb,currentColor 5%,transparent);--kt-muted:color-mix(in srgb,currentColor 65%,transparent);font:inherit;color:inherit;max-width:40rem}
.kt *{box-sizing:border-box}
.kt form{display:grid;gap:1.25rem;margin:0}
.kt fieldset{border:0;margin:0;padding:0;display:grid;gap:.6rem}
.kt legend{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.kt-type{display:grid;grid-template-columns:1fr auto auto;align-items:center;gap:.75rem 1rem;padding:.9rem 1rem;border:1px solid var(--kt-line);border-radius:var(--kt-radius,.75rem);background:var(--kt-soft)}
.kt-type.is-off{opacity:.6}
.kt-type b{display:block;font-weight:600}
.kt-type small{display:block;color:var(--kt-muted);font-size:.875em;margin-top:.15rem}
.kt-type .kt-flag{color:#b45309}
.kt-price{font-weight:600;white-space:nowrap;font-variant-numeric:tabular-nums}
.kt select,.kt input[type=text],.kt input[type=email]{font:inherit;color:inherit;background:transparent;border:1px solid var(--kt-line);border-radius:calc(var(--kt-radius,.75rem) * .66);padding:.6rem .75rem;min-height:2.75rem;width:100%}
.kt select{width:auto;min-width:4.5rem}
.kt select:focus,.kt input:focus{outline:2px solid var(--kt-accent,var(--kt-accent-fallback));outline-offset:1px}
.kt-buyer{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
.kt label{display:grid;gap:.35rem;font-size:.95em}
.kt-total{display:flex;justify-content:space-between;align-items:baseline;border-top:1px solid var(--kt-line);padding-top:1rem;font-size:1.1em}
.kt-total strong{font-variant-numeric:tabular-nums}
.kt button{font:inherit;font-weight:600;cursor:pointer;border:0;border-radius:calc(var(--kt-radius,.75rem) * .66);padding:.85rem 1.25rem;min-height:3rem;background:var(--kt-accent,var(--kt-accent-fallback));color:var(--kt-accent-text,#fff);width:100%}
.kt button:disabled{opacity:.6;cursor:default}
.kt-fine{color:var(--kt-muted);font-size:.85em;margin:0;text-align:center}
.kt-alert{border-radius:var(--kt-radius,.75rem);padding:.85rem 1rem;margin:0 0 1rem;border:1px solid}
.kt-alert ul{margin:0;padding-left:1.1rem}
.kt-alert.is-error{border-color:#dc2626;color:#b91c1c;background:color-mix(in srgb,#dc2626 7%,transparent)}
.kt-alert.is-info{border-color:var(--kt-line);background:var(--kt-soft)}
.kt-closed{border:1px dashed var(--kt-line);border-radius:var(--kt-radius,.75rem);padding:1.25rem;text-align:center;font-weight:600}
.kt-hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
@media (max-width:34rem){.kt-buyer{grid-template-columns:1fr}.kt-type{grid-template-columns:1fr auto}.kt-type .kt-price{grid-row:1;grid-column:2}.kt-type select,.kt-type .kt-soldout{grid-column:1/-1;width:100%}}
@media (prefers-color-scheme:dark){.kt-type .kt-flag{color:#fbbf24}.kt-alert.is-error{color:#fca5a5}}
</style>

@if ($message)
    <p class="kt-alert is-info" role="status">{{ $message }}</p>
@endif

@if (! $selling)
    <p class="kt-closed">{{ __('Tickets are not on sale.') }}</p>
@elseif ($sold_out)
    <p class="kt-closed">{{ __('Sold out') }}</p>
@else
    <form method="POST" action="{{ $action }}" novalidate>
        @csrf
        <input type="hidden" name="event" value="{{ $event }}">
        <div class="kt-hp" aria-hidden="true"><label>{{ __('Leave this empty') }} <input type="text" name="kt_website" tabindex="-1" autocomplete="off"></label></div>

        @if ($has_errors)
            <div class="kt-alert is-error" role="alert">
                <ul>
                    @foreach ($errors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <fieldset>
            <legend>{{ __('Tickets') }}</legend>
            @foreach ($types as $type)
                <div class="kt-type {{ $type['on_sale'] ? '' : 'is-off' }}">
                    <div>
                        <b>{{ $type['name'] }}</b>
                        @if ($type['description'])<small>{{ $type['description'] }}</small>@endif
                        @if ($type['sales_ended'])
                            <small>{{ __('Sales have ended') }}</small>
                        @elseif ($type['few_left'])
                            <small class="kt-flag">{{ trans_choice('Only :count left|Only :count left', $type['left'], ['count' => $type['left']]) }}</small>
                        @endif
                    </div>
                    <span class="kt-price">{{ $type['price_formatted'] }}</span>
                    @if ($type['on_sale'])
                        <select name="{{ $type['input_name'] }}" aria-label="{{ __('Number of :name tickets', ['name' => $type['name']]) }}" data-price="{{ $type['price'] }}">
                            @foreach ($type['options'] as $n)
                                <option value="{{ $n }}" @selected($n === $type['old_quantity'])>{{ $n }}</option>
                            @endforeach
                        </select>
                    @else
                        <span class="kt-soldout">{{ $type['sold_out'] ? __('Sold out') : '' }}</span>
                    @endif
                </div>
            @endforeach
        </fieldset>

        <div class="kt-buyer">
            <label>{{ __('Your name') }}
                <input type="text" name="name" value="{{ $old_name }}" autocomplete="name" required maxlength="120">
            </label>
            <label>{{ __('Email') }}
                <input type="email" name="email" value="{{ $old_email }}" autocomplete="email" required maxlength="190">
            </label>
        </div>

        <div class="kt-total" data-kt-total hidden>
            <span>{{ __('Total') }}</span>
            <strong data-kt-sum></strong>
        </div>

        <button type="submit" data-kt-button
            data-pay="{{ $button ?: __('Continue to payment') }}"
            data-free="{{ $button ?: __('Get tickets') }}">{{ $button ?: ($any_paid ? __('Continue to payment') : __('Get tickets')) }}</button>

        <p class="kt-fine">
            @if ($any_paid)
                {{ __('Secure payment with Stripe. Your tickets are emailed to you right away.') }}
            @else
                {{ __('Your tickets are emailed to you right away.') }}
            @endif
        </p>
    </form>

    <script>
    (function () {
        var root = document.currentScript.previousElementSibling;
        if (!root || root.tagName !== 'FORM') return;
        var selects = root.querySelectorAll('select[data-price]');
        var total = root.querySelector('[data-kt-total]');
        var sum = root.querySelector('[data-kt-sum]');
        var button = root.querySelector('[data-kt-button]');
        var format;
        try { format = new Intl.NumberFormat(document.documentElement.lang || undefined, { style: 'currency', currency: @json($currency) }); }
        catch (e) { format = { format: function (n) { return n.toFixed(2) + ' ' + @json($currency); } }; }
        function update() {
            var amount = 0, count = 0;
            selects.forEach(function (s) { var n = parseInt(s.value, 10) || 0; count += n; amount += n * parseFloat(s.dataset.price || 0); });
            total.hidden = count === 0;
            sum.textContent = amount > 0 ? format.format(amount) : @json(__('Free'));
            button.textContent = amount > 0 || count === 0 && @json($any_paid) ? button.dataset.pay : button.dataset.free;
        }
        selects.forEach(function (s) { s.addEventListener('change', update); });
        root.addEventListener('submit', function () { setTimeout(function () { button.disabled = true; }, 0); });
        update();
    })();
    </script>
@endif
</div>

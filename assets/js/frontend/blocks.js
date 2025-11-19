(function () {
    const wc = window.wc || {};
    const wp = window.wp || {};
    const registry = wc.wcBlocksRegistry;
    const settingsStore = wc.wcSettings;
    const checkoutExtensibility = wc.blocksCheckout || {};
    const wpHtml = wp.htmlEntities || {};
    const wpElement = wp.element;
    const wpI18n = wp.i18n || {};
    const wpApiFetch = wp.apiFetch;

    if (!registry || !settingsStore || !wpElement) {
        return;
    }

    const { registerPaymentMethod } = registry;
    const { getSetting } = settingsStore;
    const {
        createElement,
        Fragment,
        useCallback,
        useEffect,
        useMemo,
        useRef,
        useState,
    } = wpElement;

    const decodeEntities =
        typeof wpHtml.decodeEntities === 'function'
            ? wpHtml.decodeEntities
            : function (value) {
                  return value;
              };

    const __ =
        typeof wpI18n.__ === 'function'
            ? wpI18n.__
            : function (value) {
                  return value;
              };

    const settings = getSetting('flexiown_data', {});
    const namespace = settings.namespace || 'flexiown';
    const dataKey = settings.dataKey || 'application';
    const restUrl = settings.restUrl || '';
    const onboardingEnabled = Boolean(settings.onboardingEnabled);

    const sanitizeIdInput = function (value) {
        return value.replace(/\D/g, '').slice(0, 13);
    };

    const sanitizePhoneInput = function (value) {
        return value.replace(/\D/g, '').slice(0, 15);
    };

    const defaultApplicationData = {
        salary: '',
        isUnderDebtReview: '',
        registrationDocumentNumber: '',
        mobileNumber: '',
        employerName: '',
        employerContact: '',
        maritalStatus: '',
        nextOfKinName: '',
        nextOfKinContact: '',
        nextOfKinRelationship: '',
    };

    const canMakePayment = function () {
        return true;
    };

    const buildDefaultValues = function (prefill) {
        if (!prefill || typeof prefill !== 'object') {
            return { ...defaultApplicationData };
        }
        return { ...defaultApplicationData, ...prefill };
    };

    const useFlexiownExtensionData = function () {
        const defaults = useMemo(function () {
            return buildDefaultValues(settings.prefill);
        }, []);
        const [fallbackData, setFallbackData] = useState(defaults);
        const hasHook =
            typeof checkoutExtensibility.useCheckoutExtensionData ===
            'function';
        const apiFetchFn =
            typeof wpApiFetch === 'function' ? wpApiFetch : null;
        const lastSyncedRef = useRef(null);

        const syncServerData = useCallback(
            function (payload) {
                if (!apiFetchFn) {
                    return;
                }

                const normalized = { ...defaults, ...payload };
                const serialized = JSON.stringify(normalized);

                if (lastSyncedRef.current === serialized) {
                    return;
                }

                lastSyncedRef.current = serialized;

                const requests = [];

                requests.push(
                    apiFetchFn({
                        path: '/wc/store/v1/cart/extensions',
                        method: 'POST',
                        data: {
                            namespace,
                            data: {
                                [dataKey]: normalized,
                            },
                        },
                    })
                );

                if (restUrl) {
                    requests.push(
                        apiFetchFn({
                            url: restUrl,
                            method: 'POST',
                            data: {
                                [dataKey]: normalized,
                            },
                        })
                    );
                }

                Promise.all(requests).catch(function () {
                    // Ignore connectivity errors; checkout will fall back to in-request data.
                });
            },
            [apiFetchFn, defaults, restUrl]
        );

        if (!hasHook) {
            return {
                data: fallbackData,
                setData: function (nextData) {
                    setFallbackData(nextData);
                    syncServerData(nextData);
                },
            };
        }

        const hookValue = checkoutExtensibility.useCheckoutExtensionData();
        const extensionData = hookValue.extensionData || {};
        const setExtensionData = hookValue.setExtensionData;
        const namespaceData = extensionData[namespace] || {};
        const currentData = namespaceData[dataKey] || null;
        const initializedRef = useRef(false);
        const resolvedData = currentData || defaults;

        useEffect(
            function () {
                if (currentData) {
                    initializedRef.current = true;
                    syncServerData(currentData);
                    return;
                }

                if (!initializedRef.current) {
                    setExtensionData(namespace, dataKey, defaults);
                    initializedRef.current = true;
                    syncServerData(defaults);
                }
            },
            [currentData, defaults, setExtensionData, syncServerData]
        );

        const setData = function (nextData) {
            setExtensionData(namespace, dataKey, nextData);
            syncServerData(nextData);
        };

        return {
            data: resolvedData,
            setData,
        };
    };

    const FieldWrapper = function (children, key) {
        return createElement(
            'div',
            { className: 'flexiown-blocks__field', key: key },
            children
        );
    };

    const renderInput = function (config) {
        const {
            id,
            label,
            value,
            onChange,
            description,
            type,
            inputMode,
            autoComplete,
            maxLength,
            required,
        } = config;

        return FieldWrapper(
            [
                createElement(
                    'label',
                    { htmlFor: id, key: id + '-label' },
                    [
                        label,
                        required
                            ? createElement(
                                  'span',
                                  {
                                      className:
                                          'flexiown-blocks__required-indicator',
                                  },
                                  ' *'
                              )
                            : null,
                    ]
                ),
                createElement('input', {
                    id: id,
                    key: id + '-input',
                    type: type || 'text',
                    inputMode: inputMode,
                    autoComplete: autoComplete,
                    maxLength: maxLength,
                    value: value,
                    required: Boolean(required),
                    'aria-required': required ? 'true' : undefined,
                    onChange: function (event) {
                        onChange(event.target.value || '');
                    },
                }),
                description
                    ? createElement(
                          'p',
                          {
                              className: 'flexiown-blocks__description',
                              key: id + '-description',
                          },
                          description
                      )
                    : null,
            ],
            id
        );
    };

    const renderSelect = function (config) {
        const { id, label, value, onChange, options, description, required } = config;
        const optionElements = (options || []).map(function (option, index) {
            return createElement(
                'option',
                { value: option.value, key: id + '-option-' + index },
                decodeEntities(option.label || '')
            );
        });

        return FieldWrapper(
            [
                createElement(
                    'label',
                    { htmlFor: id, key: id + '-label' },
                    [
                        label,
                        required
                            ? createElement(
                                  'span',
                                  {
                                      className:
                                          'flexiown-blocks__required-indicator',
                                  },
                                  ' *'
                              )
                            : null,
                    ]
                ),
                createElement(
                    'select',
                    {
                        id: id,
                        key: id + '-select',
                        value: value,
                        required: Boolean(required),
                        'aria-required': required ? 'true' : undefined,
                        onChange: function (event) {
                            onChange(event.target.value || '');
                        },
                    },
                    optionElements
                ),
                description
                    ? createElement(
                          'p',
                          {
                              className: 'flexiown-blocks__description',
                              key: id + '-description',
                          },
                          description
                      )
                    : null,
            ],
            id
        );
    };

    const FlexiownFields = function () {
        const hook = useFlexiownExtensionData();
        const data = hook.data;
        const setData = hook.setData;
        const optionSets = settings.options || {};

        const updateField = function (field, value) {
            setData({
                ...data,
                [field]: value,
            });
        };

        return createElement(
            'div',
            { className: 'flexiown-blocks' },
            createElement(
                'h3',
                null,
                __('Flexiown application details', 'flexiown')
            ),
            createElement(
                'p',
                { className: 'flexiown-blocks__intro' },
                __(
                    'All Flexiown onboarding fields are required when this payment method is selected. Please complete every field before placing your order.',
                    'flexiown'
                )
            ),
            renderInput({
                id: 'flexiown-salary',
                label: __('Monthly salary', 'flexiown'),
                value: data.salary,
                onChange: function (value) {
                    updateField('salary', value.replace(/[^0-9.]/g, ''));
                },
                description: __('Numbers only. Required for Flexiown screening.', 'flexiown'),
                inputMode: 'decimal',
                autoComplete: 'off',
                required: true,
            }),
            renderSelect({
                id: 'flexiown-debt-review',
                label: __('Currently under debt review?', 'flexiown'),
                value: data.isUnderDebtReview,
                onChange: function (value) {
                    updateField('isUnderDebtReview', value);
                },
                options: optionSets.debtReview || [],
                required: true,
            }),
            renderInput({
                id: 'flexiown-id-number',
                label: __('Registration / ID number', 'flexiown'),
                value: data.registrationDocumentNumber,
                onChange: function (value) {
                    updateField(
                        'registrationDocumentNumber',
                        sanitizeIdInput(value)
                    );
                },
                description: __('Exactly 13 digits.', 'flexiown'),
                inputMode: 'numeric',
                maxLength: 13,
                required: true,
            }),
            renderInput({
                id: 'flexiown-mobile-number',
                label: __('Mobile number', 'flexiown'),
                value: data.mobileNumber,
                onChange: function (value) {
                    updateField('mobileNumber', sanitizePhoneInput(value));
                },
                description: __('Digits only (10-15). Country code optional.', 'flexiown'),
                inputMode: 'tel',
                maxLength: 15,
                autoComplete: 'tel',
                required: true,
            }),
            createElement('hr', { key: 'flexiown-divider-1' }),
            renderInput({
                id: 'flexiown-employer-name',
                label: __('Employer name', 'flexiown'),
                value: data.employerName,
                onChange: function (value) {
                    updateField('employerName', value);
                },
                required: true,
            }),
            renderInput({
                id: 'flexiown-employer-contact',
                label: __('Employer contact number', 'flexiown'),
                value: data.employerContact,
                onChange: function (value) {
                    updateField('employerContact', sanitizePhoneInput(value));
                },
                description: __(
                    'Digits only (10-15). Country code optional.',
                    'flexiown'
                ),
                inputMode: 'tel',
                maxLength: 15,
                required: true,
            }),
            renderSelect({
                id: 'flexiown-marital-status',
                label: __('Marital status', 'flexiown'),
                value: data.maritalStatus,
                onChange: function (value) {
                    updateField('maritalStatus', value);
                },
                options: optionSets.maritalStatus || [],
                required: true,
            }),
            createElement('hr', { key: 'flexiown-divider-2' }),
            renderInput({
                id: 'flexiown-kin-name',
                label: __('Next of kin name', 'flexiown'),
                value: data.nextOfKinName,
                onChange: function (value) {
                    updateField('nextOfKinName', value);
                },
                required: true,
            }),
            renderInput({
                id: 'flexiown-kin-contact',
                label: __('Next of kin contact number', 'flexiown'),
                value: data.nextOfKinContact,
                onChange: function (value) {
                    updateField('nextOfKinContact', sanitizePhoneInput(value));
                },
                inputMode: 'tel',
                maxLength: 15,
                description: __(
                    'Digits only (10-15). Country code optional.',
                    'flexiown'
                ),
                required: true,
            }),
            renderSelect({
                id: 'flexiown-kin-relationship',
                label: __('Next of kin relationship', 'flexiown'),
                value: data.nextOfKinRelationship,
                onChange: function (value) {
                    updateField('nextOfKinRelationship', value);
                },
                options: optionSets.kinRelationship || [],
                required: true,
            })
        );
    };

    const renderDescription = function () {
        return createElement(
            'div',
            { className: 'wc-block-components-payment-method-content' },
            decodeEntities(
                settings.description ||
                    'Try It, Love It, Own It. You will be redirected to FlexiownPay to securely complete your payment.'
            )
        );
    };

    const FlexiownContent = function () {
        return createElement(
            Fragment,
            null,
            renderDescription(),
            onboardingEnabled ? createElement(FlexiownFields) : null
        );
    };

    const flexiownConfig = {
        name: 'flexiown',
        label: createElement(
            'span',
            { style: { width: '100%' } },
            decodeEntities(settings.title || 'Flexiown')
        ),
        content: createElement(FlexiownContent),
        edit: createElement(FlexiownContent),
        canMakePayment: canMakePayment,
        ariaLabel: decodeEntities(settings.title || 'Flexiown'),
        supports: {
            features: settings && settings.supports ? settings.supports : ['products'],
        },
    };

    registerPaymentMethod(flexiownConfig);
})();

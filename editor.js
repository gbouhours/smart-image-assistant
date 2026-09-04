( function( wp ) {
    const { registerPlugin } = wp.plugins;
    const { PluginDocumentSettingPanel } = wp.editPost;
    const { PanelBody, Button, TextControl, TextareaControl, SelectControl, Spinner, Notice } = wp.components;
    const { useState, useEffect, createElement: el } = wp.element;
    const { __ } = wp.i18n;
    const { useSelect, useDispatch } = wp.data;
    const apiFetch = wp.apiFetch;

    const RATIO_OPTIONS = [
        { label: '16:9 — Landscape', value: '16:9' },
        { label: '2:3 — Portrait', value: '2:3' },
        { label: '1:1 — Square', value: '1:1' },
    ];

    function GeneratorPanel() {
        const postId = useSelect( ( select ) => select('core/editor')?.getCurrentPostId?.(), [] );
        const postType = useSelect( ( select ) => {
            const ed = select('core/editor');
            return ed?.getEditedPostAttribute?.('type') || ed?.getCurrentPostType?.();
        }, [] );
        const title = useSelect( ( select ) => select('core/editor')?.getEditedPostAttribute?.('title') || '', [] );
        const { editPost } = useDispatch('core/editor');

        const defaultProvider = SIASettings?.defaultProvider || 'cloudflare';
        const [provider, setProvider] = useState( defaultProvider );
        const providerDefaults = SIASettings?.providerDefaults || {};
        const activeDefaults = providerDefaults[provider] || {};
        const isCloudflare = provider === 'cloudflare';

        const [width, setWidth] = useState( activeDefaults.width || 1024 );
        const [height, setHeight] = useState( activeDefaults.height || 576 );
        const [ratio, setRatio] = useState( activeDefaults.ratio || '16:9' );

        // Custom prompt: pre-filled with the global template, editable per-post
        const [customPrompt, setCustomPrompt] = useState( SIASettings?.promptTemplate || '' );

        const [isLoading, setIsLoading] = useState(false);
        const [showAdvanced, setShowAdvanced] = useState(false);
        const [error, setError] = useState('');
        const [success, setSuccess] = useState('');
        const [previewImage, setPreviewImage] = useState(null);
        const [previewMime, setPreviewMime] = useState('image/png');
        const [previewExtension, setPreviewExtension] = useState('png');
        const [previewAttachmentId, setPreviewAttachmentId] = useState(null);
        const [isSettingFeatured, setIsSettingFeatured] = useState(false);
        const [featuredSet, setFeaturedSet] = useState(false);

        const canGenerate = !!postId && !isLoading;

        const configuredProviders = SIASettings?.providers || [];
        const providerOptions = configuredProviders.length > 0
            ? configuredProviders.map( p => ( { label: p.name, value: p.key } ) )
            : [ { label: __('No providers configured', 'smart-image-assistant'), value: '' } ];

        const handleProviderChange = ( newProvider ) => {
            setProvider( newProvider );
            const defs = providerDefaults[newProvider] || {};
            setWidth( defs.width || 1024 );
            setHeight( defs.height || 576 );
            setRatio( defs.ratio || '16:9' );
        };

        useEffect( () => {
            setProvider( defaultProvider );
        }, [ defaultProvider ] );

        const onGenerate = async () => {
            setError('');
            setSuccess('');
            setPreviewImage(null);
            setPreviewMime('image/png');
            setPreviewExtension('png');
            setPreviewAttachmentId(null);
            setFeaturedSet(false);
            if (!title || (typeof title === 'string' && title.trim() === '')) {
                setError(__('Please add a title before generating a featured image.', 'smart-image-assistant'));
                return;
            }
            if (!provider) {
                setError(__('Please select an AI provider.', 'smart-image-assistant'));
                return;
            }
            setIsLoading(true);
            try {
                const data = {
                    postId,
                    provider
                };
                if (isCloudflare) {
                    data.width = parseInt(width, 10);
                    data.height = parseInt(height, 10);
                } else {
                    data.ratio = ratio;
                }
                // Send custom prompt if user modified it
                if (customPrompt && customPrompt.trim() !== '') {
                    data.customPrompt = customPrompt.trim();
                }
                const response = await apiFetch({
                    path: `/${SIASettings.namespace}/${SIASettings.route}`,
                    method: 'POST',
                    data: data
                });
                if (response && response.imageBase64) {
                    const mime = response.mime || 'image/png';
                    const dataUrl = `data:${mime};base64,${response.imageBase64}`;
                    setPreviewImage(dataUrl);
                    setPreviewMime(mime);
                    setPreviewExtension(response.extension || 'png');
                    setPreviewAttachmentId(null);
                    setSuccess(__('Image generated successfully. Preview below.', 'smart-image-assistant'));
                } else if (response && response.error) {
                    setError(response.error);
                } else {
                    setError(__('Unexpected response from server.', 'smart-image-assistant'));
                }
            } catch (e) {
                setError(e?.message || __('Request failed.', 'smart-image-assistant'));
            } finally {
                setIsLoading(false);
            }
        };

        const onSetFeatured = async () => {
            if (!previewAttachmentId || !postId) {
                if (!previewImage) {
                    setError(__('No generated image to set. Please generate first.', 'smart-image-assistant'));
                    return;
                }
            }
            setError('');
            setSuccess('');
            setIsSettingFeatured(true);
            try {
                let payload;
                if (previewAttachmentId) {
                    payload = { postId, attachmentId: previewAttachmentId };
                } else {
                    const parts = (previewImage || '').split(',');
                    if (parts.length < 2) {
                        setError(__('Invalid preview image. Please generate again.', 'smart-image-assistant'));
                        setIsSettingFeatured(false);
                        return;
                    }
                    const base64Data = parts[1] || '';
                    payload = { postId, imageBase64: base64Data, mime: previewMime, extension: previewExtension };
                }
                const response = await apiFetch({ path: `/${SIASettings.namespace}/set-featured`, method: 'POST', data: payload });
                if (response && response.success) {
                    setSuccess(__('Featured image set successfully!', 'smart-image-assistant'));
                    const newId = response.attachmentId || previewAttachmentId;
                    if (newId) { editPost({ featured_media: newId }); }
                    setFeaturedSet(true);
                } else if (response && response.error) {
                    setError(response.error);
                } else {
                    setError(__('Failed to set featured image.', 'smart-image-assistant'));
                }
            } catch (e) {
                const apiMessage = e?.message || e?.data?.error || e?.data?.message;
                setError(apiMessage || __('Request failed.', 'smart-image-assistant'));
            } finally {
                setIsSettingFeatured(false);
            }
        };

        const enabledTypes = SIASettings?.enabledPostTypes;
        if (!postType || !Array.isArray(enabledTypes) || enabledTypes.length === 0 || !enabledTypes.includes(postType)) {
            return null;
        }

        const defaultFormat = 'PNG';

        const { Modal } = wp.components;
        const [isOpen, setOpen] = useState(false);

        return el( PluginDocumentSettingPanel,
            { name: 'smart-image-assistant-generator', title: __('Smart Image Assistant', 'smart-image-assistant'), className: 'smart-image-assistant-panel' },
            el(PanelBody, {},
                // Provider selector
                configuredProviders.length > 1 && el(SelectControl, {
                    label: __('AI Provider', 'smart-image-assistant'),
                    value: provider,
                    options: providerOptions,
                    onChange: handleProviderChange,
                }),

                // Defaults display
                !showAdvanced && el('div', { style: { marginBottom: '8px' } },
                    !!isCloudflare
                        ? el('p', {}, `${__('Using defaults', 'smart-image-assistant')}: ${activeDefaults.width || 1024}×${activeDefaults.height || 576} ${defaultFormat}`)
                        : el('p', {}, `${__('Using defaults', 'smart-image-assistant')}: ${(activeDefaults.ratio || '16:9').toUpperCase()} ${defaultFormat}`),
                    el(Button, { isLink: true, onClick: () => setShowAdvanced(true) }, __('Modify default size settings', 'smart-image-assistant'))
                ),

                // Advanced options
                showAdvanced && !!isCloudflare && el(TextControl, {
                    label: __('Width (px)', 'smart-image-assistant'),
                    type: 'number',
                    min: 64,
                    max: 2048,
                    value: width,
                    onChange: ( v ) => setWidth( parseInt(v, 10) || 64 ),
                }),
                showAdvanced && !!isCloudflare && el(TextControl, {
                    label: __('Height (px)', 'smart-image-assistant'),
                    type: 'number',
                    min: 64,
                    max: 2048,
                    value: height,
                    onChange: ( v ) => setHeight( parseInt(v, 10) || 64 ),
                }),

                showAdvanced && !isCloudflare && el(SelectControl, {
                    label: __('Aspect Ratio', 'smart-image-assistant'),
                    value: ratio,
                    options: RATIO_OPTIONS,
                    onChange: setRatio,
                }),
                
                // Custom prompt
                showAdvanced && el(TextareaControl, {
                    label: __('Custom Prompt', 'smart-image-assistant'),
                    help: __('Use {{title}} and {{excerpt}} placeholders. Edit this prompt to generate a specific style for this post (photo, illustration, etc.).', 'smart-image-assistant'),
                    rows: 5,
                    value: customPrompt,
                    onChange: setCustomPrompt,
                }),

                isLoading ? el( Spinner, {} ) : el(Button, { isPrimary: true, onClick: onGenerate, disabled: !canGenerate, style: { marginBottom: '5px'} }, __('Generate Featured Image', 'smart-image-assistant')),
                error ? el(Notice, { status: 'error', onRemove: () => setError(''), isDismissible: true }, error) : null,
                success && !featuredSet ? el(Notice, { status: 'success', onRemove: () => setSuccess(''), isDismissible: true }, success) : null,
                previewImage && el('div', { style: { marginTop: '16px' } },
                    el('div', { style: { position: 'relative' } },
                        el('img', {
                            src: previewImage,
                            alt: __('Generated image preview', 'smart-image-assistant'),
                            style: { width: '100%', height: 'auto', maxHeight: '300px', objectFit: 'contain', border: '1px solid #ddd', borderRadius: '4px', cursor: 'pointer' },
                            onClick: () => setOpen(true)
                        }),
                        el(Button, {
                            icon: 'external',
                            label: __('View Image', 'smart-image-assistant'),
                            onClick: () => setOpen(true),
                            isSmall: true,
                            style: { position: 'absolute', right: '8px', bottom: '8px', top: '8px', borderRadius: '50%', backgroundColor: 'white', boxShadow: '0 1px 20px rgba(0,124,186,1)' }
                        })
                    ),
                    el('p', { style: { marginTop: '1px', marginBottom: '1px', color: '#757575', fontSize: '12px' } }, __('(Generated Based on Title and Excerpt)', 'smart-image-assistant')),
                    isOpen && el(Modal, {
                        title: __('Generated Image Preview (Based on Title and Excerpt)', 'smart-image-assistant'),
                        onRequestClose: () => setOpen(false)
                    },
                        el('img', { src: previewImage, style: { width: '100%' } })
                    ),
                    !featuredSet && el('div', { style: { marginTop: '12px' } },
                        isSettingFeatured ? el(Spinner, {}) : el(Button, { isPrimary: true, onClick: onSetFeatured, disabled: isSettingFeatured }, __('Set as Featured Image', 'smart-image-assistant'))
                    ),
                    featuredSet && el(Notice, { status: 'success', isDismissible: false }, __('Featured image set successfully.', 'smart-image-assistant'))
                )
            )
        );
    }

    registerPlugin('smart-image-assistant-plugin', {
        render: GeneratorPanel,
        icon: 'format-image',
    });
} )( window.wp );
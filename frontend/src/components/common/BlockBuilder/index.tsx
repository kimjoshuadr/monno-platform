import {useRef, useState} from "react";
import {t} from "@lingui/macro";
import {
    ActionIcon,
    Badge,
    Button,
    Group,
    Loader,
    Menu,
    Stack,
    Text,
    Textarea,
    TextInput,
    Tooltip,
    UnstyledButton,
} from "@mantine/core";
import {
    IconChevronDown,
    IconChevronUp,
    IconEye,
    IconEyeOff,
    IconPlus,
    IconTrash,
    IconUpload,
} from "@tabler/icons-react";
import {HomepageBlock, HomepageBlockType, IdParam} from "../../../types.ts";
import {blockRegistry, blockTypeOrder, newBlockId} from "./registry";
import {useUploadImage} from "../../../mutations/useUploadImage.ts";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {extractImageUploadErrors, validateImageFile} from "../../../utilites/imageUploadValidation.ts";

interface BlockBuilderProps {
    value: HomepageBlock[];
    onChange: (blocks: HomepageBlock[]) => void;
    disabled?: boolean;
}

/** An empty object and PHP's empty array round-trip differently in jsonb. */
const asSettings = (settings?: Record<string, any>): Record<string, any> =>
    !settings || Array.isArray(settings) ? {} : settings;

/**
 * Lets an organizer assemble a homepage from sections. Order in this list is
 * render order; `visible` hides a section without deleting its content.
 *
 * Shared by the event and organizer designers — both surfaces persist the same
 * `homepage_blocks` array on their settings row.
 */
export const BlockBuilder = ({value, onChange, disabled = false}: BlockBuilderProps) => {
    const blocks = Array.isArray(value) ? value : [];
    const [expanded, setExpanded] = useState<string | null>(null);

    const update = (next: HomepageBlock[]) => onChange(next);

    const addBlock = (type: HomepageBlockType) => {
        const definition = blockRegistry[type];
        const block: HomepageBlock = {
            id: newBlockId(),
            type,
            visible: true,
            settings: {...definition.defaultSettings},
        };
        update([...blocks, block]);
        setExpanded(block.id);
    };

    const patchBlock = (id: string, patch: Partial<HomepageBlock>) =>
        update(blocks.map((block) => (block.id === id ? {...block, ...patch} : block)));

    const patchSettings = (id: string, patch: Record<string, any>) =>
        update(blocks.map((block) =>
            block.id === id ? {...block, settings: {...asSettings(block.settings), ...patch}} : block
        ));

    const move = (index: number, delta: number) => {
        const target = index + delta;
        if (target < 0 || target >= blocks.length) return;
        const next = [...blocks];
        [next[index], next[target]] = [next[target], next[index]];
        update(next);
    };

    const remove = (id: string) => {
        update(blocks.filter((block) => block.id !== id));
        if (expanded === id) setExpanded(null);
    };

    return (
        <div>
            <Text size="sm" c="dimmed" mb="xs">
                {t`Sections appear on your public page in this order.`}
            </Text>

            <Stack gap="xs">
                {blocks.map((block, index) => {
                    const definition = blockRegistry[block.type] ?? {
                        label: block.type,
                        description: '',
                        dataDriven: false,
                        icon: IconPlus,
                    };
                    const Icon = definition.icon;
                    const isOpen = expanded === block.id;
                    const hidden = block.visible === false;

                    return (
                        <div
                            key={block.id}
                            style={{
                                border: '1px solid var(--mantine-color-gray-3)',
                                borderRadius: '8px',
                                padding: '10px 12px',
                                opacity: hidden ? 0.6 : 1,
                            }}
                        >
                            <Group justify="space-between" wrap="nowrap" gap="xs">
                                <Group gap="xs" wrap="nowrap" style={{minWidth: 0}}>
                                    <Icon size={18} stroke={1.5}/>
                                    <UnstyledButton
                                        onClick={() => setExpanded(isOpen ? null : block.id)}
                                        style={{minWidth: 0, textAlign: 'left'}}
                                        aria-expanded={isOpen}
                                    >
                                        <Text size="sm" fw={500}>{definition.label}</Text>
                                        <Text size="xs" c="dimmed" lineClamp={1}>
                                            {definition.description}
                                        </Text>
                                    </UnstyledButton>
                                </Group>

                                <Group gap={4} wrap="nowrap">
                                    {hidden && (
                                        <Badge size="xs" color="gray" variant="light">{t`Hidden`}</Badge>
                                    )}
                                    <Tooltip label={hidden ? t`Show section` : t`Hide section`}>
                                        <ActionIcon
                                            variant="subtle" color="gray" aria-label={t`Toggle visibility`}
                                            disabled={disabled}
                                            onClick={() => patchBlock(block.id, {visible: hidden})}
                                        >
                                            {hidden ? <IconEyeOff size={16}/> : <IconEye size={16}/>}
                                        </ActionIcon>
                                    </Tooltip>
                                    <Tooltip label={t`Move up`}>
                                        <ActionIcon
                                            variant="subtle" color="gray" aria-label={t`Move up`}
                                            disabled={disabled || index === 0}
                                            onClick={() => move(index, -1)}
                                        >
                                            <IconChevronUp size={16}/>
                                        </ActionIcon>
                                    </Tooltip>
                                    <Tooltip label={t`Move down`}>
                                        <ActionIcon
                                            variant="subtle" color="gray" aria-label={t`Move down`}
                                            disabled={disabled || index === blocks.length - 1}
                                            onClick={() => move(index, 1)}
                                        >
                                            <IconChevronDown size={16}/>
                                        </ActionIcon>
                                    </Tooltip>
                                    <Tooltip label={t`Remove section`}>
                                        <ActionIcon
                                            variant="subtle" color="red" aria-label={t`Remove section`}
                                            disabled={disabled} onClick={() => remove(block.id)}
                                        >
                                            <IconTrash size={16}/>
                                        </ActionIcon>
                                    </Tooltip>
                                </Group>
                            </Group>

                            {isOpen && (
                                <div style={{marginTop: '10px'}}>
                                    <BlockSettingsEditor
                                        block={block}
                                        disabled={disabled}
                                        onChange={(patch) => patchSettings(block.id, patch)}
                                    />
                                </div>
                            )}
                        </div>
                    );
                })}
            </Stack>

            <Menu shadow="md" width={320} position="bottom-start">
                <Menu.Target>
                    <Button
                        variant="light" leftSection={<IconPlus size={16}/>} mt="md"
                        disabled={disabled}
                    >
                        {t`Add section`}
                    </Button>
                </Menu.Target>
                <Menu.Dropdown>
                    <Menu.Label>{t`From your event`}</Menu.Label>
                    {blockTypeOrder.filter((type) => blockRegistry[type].dataDriven).map((type) => {
                        const Icon = blockRegistry[type].icon;
                        return (
                            <Menu.Item key={type} onClick={() => addBlock(type)} leftSection={<Icon size={16}/>}>
                                {blockRegistry[type].label}
                            </Menu.Item>
                        );
                    })}
                    <Menu.Divider/>
                    <Menu.Label>{t`Write your own`}</Menu.Label>
                    {blockTypeOrder.filter((type) => !blockRegistry[type].dataDriven).map((type) => {
                        const Icon = blockRegistry[type].icon;
                        return (
                            <Menu.Item key={type} onClick={() => addBlock(type)} leftSection={<Icon size={16}/>}>
                                {blockRegistry[type].label}
                            </Menu.Item>
                        );
                    })}
                </Menu.Dropdown>
            </Menu>
        </div>
    );
};

/** One gallery image as stored inside a block's `settings.images`. */
interface GalleryImage {
    url?: string;
    alt?: string;
    image_id?: IdParam;
}

/**
 * Gallery images: upload a file or paste a link — both end up in the same
 * `url`, so one section can mix the two.
 *
 * Uploads go through the same POST /images as every dropzone in the app, but
 * without an image_type: the API records those as GENERIC images owned by the
 * account, because a section's artwork belongs to the page rather than to an
 * event or organizer row (and the organizer designer's cover/logo slots are
 * already typed and stay that way).
 */
const GalleryEditor = ({images, disabled, onChange}: {
    images: GalleryImage[];
    disabled: boolean;
    onChange: (patch: {images: GalleryImage[]}) => void;
}) => {
    const upload = useUploadImage();
    const [busyKey, setBusyKey] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const replaceIndexRef = useRef<number | null>(null);
    const replaceInputRef = useRef<HTMLInputElement | null>(null);
    const addInputRef = useRef<HTMLInputElement | null>(null);

    const patch = (index: number, next: Partial<GalleryImage>) =>
        onChange({images: images.map((x: GalleryImage, i: number) => (i === index ? {...x, ...next} : x))});

    const uploadFile = async (file: File, targetIndex: number | null) => {
        const key = targetIndex === null ? 'new' : String(targetIndex);
        const invalid = validateImageFile(file);
        if (invalid) {
            setErrors((prev) => ({...prev, [key]: invalid}));
            return;
        }

        setErrors((prev) => ({...prev, [key]: ''}));
        setBusyKey(key);

        try {
            const response = await upload.mutateAsync({image: file});
            const url = response?.data?.url;

            if (url) {
                if (targetIndex === null) {
                    onChange({images: [...images, {url, alt: '', image_id: response?.data?.id}]});
                } else {
                    patch(targetIndex, {url, image_id: response?.data?.id});
                }
                showSuccess(t`Image uploaded`);
            } else {
                setErrors((prev) => ({
                    ...prev,
                    [key]: t`The upload did not return an image URL. Please try again.`,
                }));
            }
        } catch (error) {
            setErrors((prev) => ({...prev, [key]: extractImageUploadErrors(error).join(' ')}));
        } finally {
            setBusyKey(null);
            replaceIndexRef.current = null;
            if (replaceInputRef.current) replaceInputRef.current.value = '';
            if (addInputRef.current) addInputRef.current.value = '';
        }
    };

    return (
        <div>
            <Text size="sm" fw={500} mb={6}>{t`Images`}</Text>
            <Stack gap="sm">
                {images.map((image: GalleryImage, index: number) => {
                    const key = String(index);
                    const uploading = busyKey === key;

                    return (
                        <div key={index}>
                            <div style={{display: 'flex', gap: '8px', alignItems: 'flex-end'}}>
                                {image.url ? (
                                    <img
                                        src={image.url}
                                        alt={image.alt || ''}
                                        style={{
                                            width: 44, height: 44, objectFit: 'cover', borderRadius: 6,
                                            border: '1px solid var(--mantine-color-gray-3)', flexShrink: 0,
                                        }}
                                    />
                                ) : (
                                    <div style={{
                                        width: 44, height: 44, borderRadius: 6, flexShrink: 0,
                                        border: '1px dashed var(--mantine-color-gray-4)',
                                    }}/>
                                )}
                                <TextInput
                                    label={index === 0 ? t`Image URL` : undefined}
                                    placeholder={t`https://…`}
                                    type="url"
                                    style={{flex: 1.4, minWidth: 0}} disabled={disabled}
                                    value={image.url || ''}
                                    onChange={(e) => patch(index, {url: e.currentTarget.value})}
                                />
                                <TextInput
                                    label={index === 0 ? t`Alt text` : undefined}
                                    placeholder={t`Crowd under red light`}
                                    style={{flex: 1, minWidth: 0}} disabled={disabled}
                                    value={image.alt || ''}
                                    onChange={(e) => patch(index, {alt: e.currentTarget.value})}
                                />
                                <Tooltip label={t`Upload image`}>
                                    <ActionIcon
                                        variant="light" color="gray" aria-label={t`Upload image`} mt={24}
                                        disabled={disabled || (busyKey !== null && !uploading)}
                                        onClick={() => {
                                            replaceIndexRef.current = index;
                                            replaceInputRef.current?.click();
                                        }}
                                    >
                                        {uploading ? <Loader size={14}/> : <IconUpload size={16}/>}
                                    </ActionIcon>
                                </Tooltip>
                                <ActionIcon
                                    variant="subtle" color="red" aria-label={t`Remove`} mt={24}
                                    disabled={disabled || images.length === 1}
                                    onClick={() => onChange({images: images.filter((_: GalleryImage, i: number) => i !== index)})}
                                >
                                    <IconTrash size={16}/>
                                </ActionIcon>
                            </div>
                            {errors[key] && (
                                <Text size="xs" c="red" mt={4}>{errors[key]}</Text>
                            )}
                        </div>
                    );
                })}
            </Stack>

            <Group gap="xs" mt="sm">
                <Button
                    size="xs" variant="light" leftSection={<IconUpload size={14}/>}
                    disabled={disabled || (busyKey !== null && busyKey !== 'new')}
                    loading={busyKey === 'new'}
                    onClick={() => addInputRef.current?.click()}
                >
                    {t`Upload image`}
                </Button>
                <Button
                    size="xs" variant="subtle" disabled={disabled}
                    onClick={() => onChange({images: [...images, {url: 'https://', alt: ''}]})}
                >
                    {t`Add a link`}
                </Button>
            </Group>

            {errors['new'] && <Text size="xs" c="red" mt={4}>{errors['new']}</Text>}

            <input
                ref={replaceInputRef}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                hidden
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    const index = replaceIndexRef.current;
                    if (file && index !== null) void uploadFile(file, index);
                }}
            />
            <input
                ref={addInputRef}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                hidden
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) void uploadFile(file, null);
                }}
            />
        </div>
    );
};

/** Per-type authoring fields; data-driven types show what they pull from. */
const BlockSettingsEditor = ({
    block,
    disabled,
    onChange,
}: {
    block: HomepageBlock;
    disabled: boolean;
    onChange: (patch: Record<string, any>) => void;
}) => {
    const settings = asSettings(block.settings);

    switch (block.type) {
        case 'TEXT':
            return (
                <Textarea
                    label={t`Text`} rows={4} maxLength={20000} disabled={disabled}
                    value={settings.body || ''}
                    onChange={(e) => onChange({body: e.currentTarget.value})}
                />
            );

        case 'CTA':
            return (
                <div style={{display: 'flex', gap: '12px', flexWrap: 'wrap'}}>
                    <TextInput
                        label={t`Label`} style={{flex: 1, minWidth: 180}} disabled={disabled}
                        value={settings.label || ''}
                        onChange={(e) => onChange({label: e.currentTarget.value})}
                    />
                    <TextInput
                        label={t`Link`} type="url" style={{flex: 2, minWidth: 220}} disabled={disabled}
                        value={settings.url || ''}
                        onChange={(e) => onChange({url: e.currentTarget.value})}
                    />
                </div>
            );

        case 'EMBED':
            return (
                <TextInput
                    label={t`Embed URL`} type="url" disabled={disabled}
                    value={settings.url || ''}
                    onChange={(e) => onChange({url: e.currentTarget.value})}
                />
            );

        case 'FAQ': {
            const items = Array.isArray(settings.items) ? settings.items : [];
            return (
                <div>
                    <Text size="sm" fw={500} mb={6}>{t`Questions`}</Text>
                    <Stack gap="sm">
                        {items.map((item: any, index: number) => (
                            <div key={index} style={{display: 'flex', gap: '8px', alignItems: 'flex-start'}}>
                                <TextInput
                                    label={index === 0 ? t`Question` : undefined}
                                    placeholder={t`Is there parking?`}
                                    style={{flex: 1, minWidth: 0}} disabled={disabled}
                                    value={item.question || ''}
                                    onChange={(e) => onChange({
                                        items: items.map((x: any, i: number) =>
                                            i === index ? {...x, question: e.currentTarget.value} : x),
                                    })}
                                />
                                <TextInput
                                    label={index === 0 ? t`Answer` : undefined}
                                    placeholder={t`Street parking after 6pm`}
                                    style={{flex: 1.4, minWidth: 0}} disabled={disabled}
                                    value={item.answer || ''}
                                    onChange={(e) => onChange({
                                        items: items.map((x: any, i: number) =>
                                            i === index ? {...x, answer: e.currentTarget.value} : x),
                                    })}
                                />
                                <ActionIcon
                                    variant="subtle" color="red" aria-label={t`Remove`} mt={24}
                                    disabled={disabled || items.length === 1}
                                    onClick={() => onChange({items: items.filter((_: any, i: number) => i !== index)})}
                                >
                                    <IconTrash size={16}/>
                                </ActionIcon>
                            </div>
                        ))}
                    </Stack>
                    <Button
                        size="xs" variant="light" mt="sm" disabled={disabled}
                        onClick={() => onChange({items: [...items, {question: '', answer: ''}]})}
                    >
                        {t`Add question`}
                    </Button>
                </div>
            );
        }

        case 'LINEUP': {
            const artists = Array.isArray(settings.artists) ? settings.artists : [];
            return (
                <div>
                    <Text size="sm" fw={500} mb={6}>{t`Artists`}</Text>
                    <Stack gap="sm">
                        {artists.map((artist: any, index: number) => (
                            <div key={index} style={{display: 'flex', gap: '8px', alignItems: 'flex-end'}}>
                                <TextInput
                                    label={index === 0 ? t`Name` : undefined}
                                    placeholder={t`Basil Wren`}
                                    style={{flex: 1, minWidth: 0}} disabled={disabled}
                                    value={artist.name || ''}
                                    onChange={(e) => onChange({
                                        artists: artists.map((x: any, i: number) =>
                                            i === index ? {...x, name: e.currentTarget.value} : x),
                                    })}
                                />
                                <ActionIcon
                                    variant="subtle" color="red" aria-label={t`Remove`} mt={24}
                                    disabled={disabled || artists.length === 1}
                                    onClick={() => onChange({artists: artists.filter((_: any, i: number) => i !== index)})}
                                >
                                    <IconTrash size={16}/>
                                </ActionIcon>
                            </div>
                        ))}
                    </Stack>
                    <Button
                        size="xs" variant="light" mt="sm" disabled={disabled}
                        onClick={() => onChange({artists: [...artists, {name: ''}]})}
                    >
                        {t`Add artist`}
                    </Button>
                </div>
            );
        }

        case 'GALLERY':
            return (
                <GalleryEditor
                    images={Array.isArray(settings.images) ? settings.images : []}
                    disabled={disabled}
                    onChange={onChange}
                />
            );

        default: {
            const definition = blockRegistry[block.type as HomepageBlockType];
            return (
                <Text size="sm" c="dimmed">
                    {definition
                        ? t`No settings — this section is filled in from your event and organizer details.`
                        : t`Unknown section type.`}
                </Text>
            );
        }
    }
};

export default BlockBuilder;

import { AppPageProps } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { Action, toast as sonner } from 'vue-sonner';
import { isSignatureMessage, signatureToast } from '@/lib/signatureToast';

export type TNotification = {
    id?: string;
    type: string;
    title: string;
    message: string;
    action?: {
        title: string;
        url: string;
        external: boolean;
    };
};

/**
 * The flash notification can ride on more than one Inertia response (a redirect and a
 * concurrent partial reload racing the session flash), and each response delivers a new
 * prop object. Tracked module-wide so the same notification is only ever toasted once.
 */
let last_notification_id: string | null = null;

export function useNotifications() {
    const page = usePage<
        AppPageProps<{
            notification?: TNotification;
        }>
    >();

    watch(
        () => page.props.notification,
        (notification) => {
            if (notification) {
                if (notification.id && notification.id === last_notification_id) {
                    return;
                }

                last_notification_id = notification.id ?? null;

                // Patch 21: signature messages show in their own column.
                if (isSignatureMessage(notification.title, notification.message)) {
                    const method = (['success', 'error', 'warning', 'info'] as const).find((type) => type === notification.type);
                    const action = getToastAction(notification.action);
                    const signatureOptions = {
                        description: notification.message,
                        action: action ? { label: String(action.label), onClick: (event: MouseEvent) => action.onClick(event) } : undefined,
                    };
                    if (method) signatureToast[method](notification.title, signatureOptions);
                    else signatureToast(notification.title, signatureOptions);
                    return;
                }
                const toast = sonner;
                const options = {
                    description: notification.message,
                    action: getToastAction(notification.action),
                };

                switch (notification.type) {
                    case 'success':
                        toast.success(notification.title, options);
                        break;
                    case 'error':
                        toast.error(notification.title, options);
                        break;
                    case 'warning':
                        toast.warning(notification.title, options);
                        break;
                    case 'info':
                        toast.info(notification.title, options);
                        break;
                    default:
                        toast(notification.title, options);
                }
            }
        },
        { deep: true, immediate: true },
    );
}

function getToastAction(action: TNotification['action']): Action | undefined {
    if (!action) return undefined;

    if (action.external) {
        return {
            label: action.title,
            onClick: () => {
                window.open(action.url, '_blank', 'noopener noreferrer');
            },
        };
    }

    return {
        label: action.title,
        onClick: () => {
            router.visit(action.url);
        },
    };
}

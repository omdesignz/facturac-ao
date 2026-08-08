export interface NotificationEntry {
    id: string;
    topic: string | null;
    topic_label: string | null;
    title: string;
    body: string;
    url: string | null;
    tone: string;
    read: boolean;
    created_at: string | null;
}

export interface NotificationState {
    unread: number;
    recent: NotificationEntry[];
}

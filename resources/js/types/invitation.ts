export type OrganizationMember = {
    id: number;
    name: string;
    email: string;
    role: string;
    status: string;
    isOwner: boolean;
};

export type PendingInvitation = {
    id: number;
    email: string;
    role: string;
    expiresAt: string;
    invitedBy: string | null;
};

export type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

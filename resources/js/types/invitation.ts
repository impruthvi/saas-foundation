export type OrganizationMember = {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    status: string;
    isOwner: boolean;
    isYou: boolean;
    canManage: boolean;
};

export type PendingInvitation = {
    id: number;
    email: string;
    roleLabel: string;
    expiresAt: string;
    invitedBy: string | null;
};

export type RankOption = {
    value: string;
    label: string;
};

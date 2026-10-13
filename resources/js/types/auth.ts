export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    role: UserRole;
    is_active: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type UserRole = 'admin' | 'user';

export type Auth = {
    user: User;
    isAdmin: boolean;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};

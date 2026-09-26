// What the signed-in account may do, from GET /v1/users/me `permissions`. Presentation only:
// hiding a button the API would refuse. The API checks every permission itself.
export const can = (user, permission) => Boolean(user?.permissions?.includes(permission));

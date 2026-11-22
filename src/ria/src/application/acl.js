


export default {
	getAcl(app)
	{
		const confAcl = app.conf.getAcl() ?? {};
		const currentSpaceId = app.conf.getSpace()?.id ?? null;
		const userGlobalRoles = confAcl.user_global_roles ?? {};
		const userGlobalPermissions = confAcl.user_global_permissions ?? {};
		const spaceParentIds = confAcl.space_parent_ids ?? {};

		const hasInSpaceOrParent = (valuesBySpace, value, spaceId) => {
			const visitedSpaceIds = new Set()

			while (spaceId !== null && spaceId !== undefined) {
				const key = String(spaceId)
				if (visitedSpaceIds.has(key)) return false
				visitedSpaceIds.add(key)

				if (valuesBySpace[key]?.includes(value)) return true
				if (!Object.hasOwn(spaceParentIds, key)) return false

				spaceId = spaceParentIds[key]
			}

			return false
		};

		return {

			isRoot() {
				return userGlobalPermissions['1']?.includes('plg_core__ROOT') ?? false;
			},

			ROLE_GEST: 1,
			ROLE_ROOT: 2,
			ROLE_USER: 3,
			ROLE_MEMBER: 5,
			PERM_ROOT: 'plg_core__ROOT',
			
			hasGlobalRole(roleId, spaceId = null){
				return hasInSpaceOrParent(userGlobalRoles, roleId, spaceId ?? currentSpaceId);
			},

			hasGlobalPermission(permission, spaceId = null){
				if (permission.indexOf('__') == -1) permission = 'plg_core__' + permission;
				// ROOT ne dépend pas du Space de navigation et n'accorde pas les autres permissions.
				if (permission === 'plg_core__ROOT') return userGlobalPermissions['1']?.includes(permission) ?? false;
				return hasInSpaceOrParent(userGlobalPermissions, permission, spaceId ?? currentSpaceId);
			},

		}
	}
}

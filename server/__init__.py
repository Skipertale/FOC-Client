def lockdown_check():
    """
    To be used with net_cmd_* functions only
    """
    #TODO: фильтр по списку разрешённых челов
    import functools
    from .exceptions import ClientError

    def decorator(func):
        @functools.wraps(func)
        def wrapper_lockdown_check(proto, arg, *args, **kwargs):
            if (
                not proto.client.is_mod
                and proto.server.is_locked_down
            ):
                raise ClientError("Server is under lockdown.")
            func(proto, arg, *args, **kwargs)

        return wrapper_lockdown_check

    return decorator
    
def lockdown_guard():
    #from .tsuserver import release_act
    import functools

    def decorator(func):
        @functools.wraps(func)
        def wrapper_lockdown_guard(client, *args, **kwargs):
            result = func(client, *args, **kwargs)
            if client.server.client_manager.get_mods() == 0:
                client.server.release_act()
            return result

        return wrapper_lockdown_guard
        
    return decorator
    


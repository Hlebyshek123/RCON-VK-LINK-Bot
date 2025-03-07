**📝 Конфигурация**
```yaml
# Файл конфигурации бота

#=====================================#
#            ВК НАСТРОЙКИ             #
#=====================================#

# ID сообщества VK (минус уже стоит внутри) 
group_id: 2194696 # Замените на ID вашего сообщества

# ID сообщества логов
logs_group_id: 2292390

# ID обсуждения в логах
logs_topic_id: 538441
from_group: 1 #если 0 отправка идет от админа если 1 то от сообщества)

# Токен доступа ВК для бота
access_token: "vk1.-9amAP1Emkfbkxf9mzu013-SI8JVUw8yPpT4CAYaQ7rJlooznBCvXUI5CEXBeEIkCxjwDxWHchGZMa97lLmSH5yhvjbYf8g_dOBBKErFvBMMi1wzIYIdezn2d_eokYVYgyIW6Svjr_6qAa996AXJrlYj7zufEaVYr2_0rRlFdSwcs9qSWD4y93hDQcobygLFr1B6Wv1w"

# Токен доступа сообщества Логов (нужен токен пользователя админа)
logs_admin_token: "vk1.a.J_OqO0bbB-kkyqriw4jK2OikQkbcqQrHxJwLt8qdeTBpAsPc5JsynyNWo1WGeIAzk_cGBLpVnKPwv4d3yPOvCKFwGU7eLLbs0LMuEc0hmbjfKuxqGl85i1D3hkqu8UGcRpvGZj21sE6N0wEW2xohwq9IglEo4i0HlkIe9ZJnRhHT3KT7WTacNWEs8dHYm27bOOkw"

#=====================================#
#          RCON Настройки             #
#=====================================#

# Параметры ркон серверов
servers:
  zaparik:
    rcon_host: "178.236.243.51"
    rcon_port: 19133
    rcon_password: "ytql0D6c"
  rolton:
    rcon_host: "178.236.243.51"
    rcon_port: 11
    rcon_password: "R3D+JVhV"
# список разрешенных серверов для рангов сервера
serv_perms:
  1lvl:
    - "rolton"
  2lvl:
    - "rolton"
    - "zaparik"
  3lvl:
    - "rolton"
    - "zaparik"
  4lvl:
    - "zaparik"
    - "rolton"
  5lvl:
    - "rolton"
  TechAdmin:
    - "rolton"
    - "zaparik"
  SuperAdmin:
    - "rolton"
    - "zaparik"

# Список всех существующих рангов
# чем ниже тем пизже
valid_ranks:
  valid:
  - "0"
  - "1lvl"
  - "2lvl"
  - "3lvl"
  - "4lvl"
  - "5lvl"
  - "SuperAdmin"
  - "TechAdmin"
# Списки разрешённых команд для каждого ранга
permissions:
  1lvl:
    - "list"
    - "say"
  2lvl:
    - "say"
    - "list"
  3lvl:
    - "hban"
    - "hmute"
  4lvl:
    - "fmute"
    - "fban"
    - "say"
    - "usrinfo"
    - "fkick"
    - "msg"
    - "list"
    - "fpardon"
    - "time"
  5lvl:
    - "*"
  TechAdmin:
    - "*"
  SuperAdmin:
    - "*"  # "*" Всё Команды
#=====================================#
#          ВЫСШИЕ АДМИНЫ              #
#=====================================#
 # Админы вк (доступ к командам "помощь админ, руководство" и до 5акков на один вк айди)
admins:
  - 000000000  # VK ID первого админа
  - 000000000  # VK ID второго админа
  - 789886979  # VK ID третьего админа
  - 662899836  # и тд

#=====================================#
#          НАСТРОЙКИ MySQL            #
#=====================================#

mysql_host: 'sql7.freesqldatabase.com' #Хост
mysql_user: 'sql7762171' #Пользователь 
mysql_pass: 'pBezl7BsfF' #Пароль
mysql_server_db: 'sql7762171' #база данных для данных сервера
mysql_bot_db: 'sql7762171' # бд для данных бота


#=====================================#
#           РАЗРЕШЕНИЯ                #
#=====================================#

# какие ранги имеют доступ к командам из "помощь админ"
allowed_ranks:
  report:
    - "2lvl"
    - "3lvl"
    - "4lvl"
    - "5lvl"
    - "TechAdmin"
    - "SuperAdmin"
  vk_info:
    - "SuperAdmin"
    - "TechAdmin"
    - "2lvl"
    - "3lvl"
    - "4lvl"
    - "5lvl"
  user_list:
    - "SuperAdmin"
    - "TechAdmin"
    - "1lvl"
    - "2lvl"
    - "3lvl"
    - "4lvl"
    - "5lvl"
  admin_help:
    - "TechAdmin"
    - "SuperAdmin"
    - "2lvl"
    - "3lvl"
    - "4lvl"
    - "5lvl"
```
